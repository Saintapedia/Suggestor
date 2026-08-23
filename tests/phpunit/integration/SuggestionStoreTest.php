<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Integration;

use MediaWiki\Extension\SaintapediaSuggest\SuggestionStore;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/**
 * Database-backed behaviour of SuggestionStore.
 *
 * Run through MediaWiki's own runner, not the standalone unit config:
 *
 *   php tests/phpunit/phpunit.php \
 *       extensions/SaintapediaSuggest/tests/phpunit/integration/
 *
 * @group Database
 * @group SaintapediaSuggest
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestionStore
 */
class SuggestionStoreTest extends MediaWikiIntegrationTestCase {

	private SuggestionStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed[] = 'sps_suggestion';
		$this->tablesUsed[] = 'sps_suggestion_log';
		$this->store = MediaWikiServices::getInstance()
			->getService( 'SaintapediaSuggest.SuggestionStore' );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = [] ): array {
		return $overrides + [
			'pageId'          => 4242,
			'namespace'       => NS_MAIN,
			'title'           => 'Test_Parish',
			'cargoTable'      => 'Parishes',
			'cargoField'      => 'Phone',
			'currentValue'    => '555-0100',
			'suggestedValue'  => '555-0199',
			'comment'         => null,
			'userId'          => null,
			'ipHash'          => str_repeat( 'a', 64 ),
			'contactEmail'    => null,
			'mode'            => 'public',
			'mergeDuplicates' => true,
		];
	}

	public function testInsertAndFetch(): void {
		$id = $this->store->insert( $this->row( [ 'comment' => 'Number changed.' ] ) );
		$this->assertGreaterThan( 0, $id );

		$row = $this->store->getById( $id );
		$this->assertNotNull( $row );
		$this->assertSame( 'Parishes', (string)$row->sg_cargo_table );
		$this->assertSame( 'Phone', (string)$row->sg_cargo_field );
		$this->assertSame( '555-0100', (string)$row->sg_current_value );
		$this->assertSame( '555-0199', (string)$row->sg_suggested_value );
		$this->assertSame( 'new', (string)$row->sg_status );
		$this->assertSame( 0, (int)$row->sg_duplicate_count );
		$this->assertNull( $row->sg_duplicate_of );
	}

	public function testRateLimitBlocksAtTheCap(): void {
		$data = $this->row( [ 'ipHash' => str_repeat( 'b', 64 ) ] );

		// Distinct values so the limit, not deduplication, is what stops us.
		$this->assertNotNull( $this->store->tryInsertUnderLimit(
			$data + [], 2 ) );
		$data['suggestedValue'] = '555-0200';
		$this->assertNotNull( $this->store->tryInsertUnderLimit( $data, 2 ) );

		$data['suggestedValue'] = '555-0300';
		$this->assertNull(
			$this->store->tryInsertUnderLimit( $data, 2 ),
			'The third submission from one IP must be refused at a limit of 2'
		);
	}

	public function testRateLimitOfZeroRefusesEverything(): void {
		$this->assertNull( $this->store->tryInsertUnderLimit( $this->row(), 0 ) );
	}

	public function testCountRecentByIpHashIgnoresOtherIps(): void {
		$this->store->insert( $this->row( [ 'ipHash' => str_repeat( 'c', 64 ) ] ) );
		$this->assertSame( 1, $this->store->countRecentByIpHash( str_repeat( 'c', 64 ) ) );
		$this->assertSame( 0, $this->store->countRecentByIpHash( str_repeat( 'd', 64 ) ) );
	}

	public function testDuplicateIsFoldedIntoTheCanonicalRow(): void {
		$first = $this->store->tryInsertUnderLimit( $this->row(), 50 );

		// Same value, different reader, different spacing and case.
		$second = $this->store->tryInsertUnderLimit(
			$this->row( [
				'suggestedValue' => '  555-0199 ',
				'ipHash'         => str_repeat( 'e', 64 ),
			] ),
			50
		);

		$this->assertNotNull( $second );
		$this->assertNotSame( $first, $second, 'The duplicate is still stored as its own row' );

		$dup = $this->store->getById( $second );
		$this->assertSame( $first, (int)$dup->sg_duplicate_of );

		$canonical = $this->store->getById( $first );
		$this->assertSame( 1, (int)$canonical->sg_duplicate_count );
		$this->assertNull( $canonical->sg_duplicate_of );

		// The dashboard shows one queue item, not two.
		$this->assertCount( 1, $this->store->getDashboard( [ 'status' => 'all' ] ) );
		$this->assertSame( 1, $this->store->countDashboard( [ 'status' => 'all' ] ) );
		$this->assertCount( 1, $this->store->getDuplicates( $first ) );
	}

	public function testDifferentValueIsNotADuplicate(): void {
		$first = $this->store->tryInsertUnderLimit( $this->row(), 50 );
		$second = $this->store->tryInsertUnderLimit(
			$this->row( [ 'suggestedValue' => '555-0999', 'ipHash' => str_repeat( 'f', 64 ) ] ),
			50
		);

		$this->assertNull( $this->store->getById( $second )->sg_duplicate_of );
		$this->assertSame( 0, (int)$this->store->getById( $first )->sg_duplicate_count );
		$this->assertSame( 2, $this->store->countDashboard( [ 'status' => 'all' ] ) );
	}

	public function testDifferentFieldIsNotADuplicate(): void {
		$this->store->tryInsertUnderLimit( $this->row(), 50 );
		$other = $this->store->tryInsertUnderLimit(
			$this->row( [ 'cargoField' => 'Website', 'ipHash' => str_repeat( '1', 64 ) ] ),
			50
		);
		$this->assertNull( $this->store->getById( $other )->sg_duplicate_of );
	}

	/**
	 * A dismissed suggestion must not silently absorb a fresh report of the
	 * same value — that repeat is evidence the dismissal may have been wrong.
	 */
	public function testDismissedRowDoesNotAbsorbNewDuplicates(): void {
		$first = $this->store->tryInsertUnderLimit( $this->row(), 50 );
		$this->store->updateStatus( $first, 'dismissed', null, 1 );

		$second = $this->store->tryInsertUnderLimit(
			$this->row( [ 'ipHash' => str_repeat( '2', 64 ) ] ),
			50
		);
		$this->assertNull(
			$this->store->getById( $second )->sg_duplicate_of,
			'A new report after a dismissal deserves its own queue item'
		);
	}

	public function testMergeCanBeDisabled(): void {
		$first = $this->store->tryInsertUnderLimit(
			$this->row( [ 'mergeDuplicates' => false ] ), 50 );
		$second = $this->store->tryInsertUnderLimit(
			$this->row( [ 'mergeDuplicates' => false, 'ipHash' => str_repeat( '3', 64 ) ] ), 50 );

		$this->assertNull( $this->store->getById( $second )->sg_duplicate_of );
		$this->assertSame( 0, (int)$this->store->getById( $first )->sg_duplicate_count );
	}

	public function testUpdateStatusWritesAnAuditEntry(): void {
		$id = $this->store->insert( $this->row() );

		$this->assertTrue( $this->store->updateStatus(
			$id, 'actioned', null, 7, [ 'workNote' => 'Fixed in the template.' ] ) );

		$row = $this->store->getById( $id );
		$this->assertSame( 'actioned', (string)$row->sg_status );
		$this->assertSame( 7, (int)$row->sg_status_user_id );
		$this->assertSame( 'Fixed in the template.', (string)$row->sg_work_note );

		$log = $this->store->getStatusLog( $id );
		$this->assertCount( 1, $log );
		$this->assertSame( 'new', (string)$log[0]->slog_old_status );
		$this->assertSame( 'actioned', (string)$log[0]->slog_new_status );
		$this->assertSame( 7, (int)$log[0]->slog_user_id );
		$this->assertSame( 'Fixed in the template.', (string)$log[0]->slog_note );
	}

	public function testUpdateStatusRejectsUnknownStatus(): void {
		$id = $this->store->insert( $this->row() );
		$this->assertFalse( $this->store->updateStatus( $id, 'banana' ) );
		$this->assertSame( 'new', (string)$this->store->getById( $id )->sg_status );
	}

	/**
	 * The per-page view passes its page id so a forged suggestion id from
	 * another article cannot be mutated through it.
	 */
	public function testUpdateStatusHonoursThePageGuard(): void {
		$id = $this->store->insert( $this->row( [ 'pageId' => 100 ] ) );

		$this->assertFalse( $this->store->updateStatus( $id, 'dismissed', 999, 1 ) );
		$this->assertSame( 'new', (string)$this->store->getById( $id )->sg_status );

		$this->assertTrue( $this->store->updateStatus( $id, 'dismissed', 100, 1 ) );
		$this->assertSame( 'dismissed', (string)$this->store->getById( $id )->sg_status );
	}

	public function testBulkUpdateLogsEveryTransition(): void {
		$a = $this->store->insert( $this->row( [ 'suggestedValue' => 'A' ] ) );
		$b = $this->store->insert( $this->row( [ 'suggestedValue' => 'B' ] ) );
		$this->store->updateStatus( $b, 'reviewed', null, 1 );

		$n = $this->store->updateStatusBulk( [ $a, $b ], 'actioned', 5, 'Batch triage.' );
		$this->assertSame( 2, $n );

		// Each row logs its own real transition, not a shared placeholder.
		$this->assertSame( 'new', (string)$this->store->getStatusLog( $a )[0]->slog_old_status );
		$logB = $this->store->getStatusLog( $b );
		$this->assertSame( 'reviewed', (string)end( $logB )->slog_old_status );
		$this->assertSame( 'actioned', (string)end( $logB )->slog_new_status );
	}

	public function testBulkUpdateRejectsNewAsATarget(): void {
		$id = $this->store->insert( $this->row() );
		$this->assertSame( 0, $this->store->updateStatusBulk( [ $id ], 'new', 1 ) );
	}

	public function testCountByStatusAndPageCounts(): void {
		$a = $this->store->insert( $this->row( [ 'suggestedValue' => 'A', 'pageId' => 55 ] ) );
		$this->store->insert( $this->row( [ 'suggestedValue' => 'B', 'pageId' => 55 ] ) );
		$this->store->updateStatus( $a, 'actioned', null, 1 );

		$counts = $this->store->countByStatus( [] );
		$this->assertSame( 1, $counts['new'] );
		$this->assertSame( 1, $counts['actioned'] );
		$this->assertSame( 2, $counts['all'] );

		$page = $this->store->getPageCounts( 55 );
		$this->assertSame( 1, $page['new'] );
		$this->assertSame( 1, $page['open'] );
		$this->assertSame( 1, $page['resolved'] );
		$this->assertSame( 2, $page['total'] );
	}

	public function testSearchMatchesValuesAndComments(): void {
		$this->store->insert( $this->row( [
			'suggestedValue' => 'Sacred Heart', 'comment' => 'per the 2026 directory' ] ) );
		$this->store->insert( $this->row( [ 'suggestedValue' => 'Holy Cross' ] ) );

		$this->assertCount( 1, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => 'Sacred' ] ) );
		$this->assertCount( 1, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => 'directory' ] ) );
		$this->assertCount( 0, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => 'nonexistent' ] ) );
	}

	/**
	 * A literal % must be searched for, not treated as a wildcard.
	 */
	public function testSearchTreatsWildcardsAsLiterals(): void {
		$this->store->insert( $this->row( [ 'suggestedValue' => '50% capacity' ] ) );
		$this->store->insert( $this->row( [ 'suggestedValue' => 'no percentage here' ] ) );

		$this->assertCount( 1, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => '50%' ] ) );
	}

	public function testContactEmailsAreNotInListQueries(): void {
		$id = $this->store->insert( $this->row( [ 'contactEmail' => 'reader@example.org' ] ) );

		$rows = $this->store->getDashboard( [ 'status' => 'all' ] );
		// property_exists rather than assertObjectNotHasAttribute(): that
		// assertion is deprecated in PHPUnit 9.6 and gone in 10, and its
		// replacement does not exist in older 9.x.
		$this->assertFalse(
			property_exists( $rows[0], 'sg_contact_email' ),
			'List queries must not materialize the contact email column'
		);
		$this->assertFalse( property_exists( $rows[0], 'sg_ip_hash' ) );

		// …but are reachable through the dedicated, right-gated accessor.
		$this->assertSame(
			[ $id => 'reader@example.org' ],
			$this->store->getContactEmailsById( [ $id ] )
		);
	}

	public function testBatchExportMarksRowsOnce(): void {
		$a = $this->store->insert( $this->row( [ 'suggestedValue' => 'A' ] ) );
		$b = $this->store->insert( $this->row( [ 'suggestedValue' => 'B' ] ) );
		$closed = $this->store->insert( $this->row( [ 'suggestedValue' => 'C' ] ) );
		$this->store->updateStatus( $closed, 'dismissed', null, 1 );

		$pending = $this->store->getPendingBatch( 10 );
		$ids = array_map( static fn ( $r ) => (int)$r->sg_id, $pending );
		sort( $ids );
		$this->assertSame( [ $a, $b ], $ids, 'Closed suggestions are not exported' );

		$this->assertSame( 2, $this->store->markBatchProcessed( $ids ) );
		$this->assertSame( [], $this->store->getPendingBatch( 10 ) );
	}

	public function testTargetFacets(): void {
		$this->store->insert( $this->row( [ 'suggestedValue' => 'A' ] ) );
		$this->store->insert( $this->row( [ 'suggestedValue' => 'B' ] ) );
		$this->store->insert( $this->row( [ 'cargoField' => 'Website', 'suggestedValue' => 'C' ] ) );

		$facets = $this->store->getTargetFacets();
		$this->assertCount( 2, $facets );
		$this->assertSame( 'Phone', $facets[0]['field'] );
		$this->assertSame( 2, $facets[0]['count'] );
	}
}
