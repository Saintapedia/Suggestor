<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\SuggestFilters;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestFilters
 */
class SuggestFiltersTest extends TestCase {

	public function testNormalizeStatusAcceptsKnownStatuses(): void {
		foreach ( SuggestFilters::VALID_STATUSES as $status ) {
			$this->assertSame( $status, SuggestFilters::normalizeStatus( $status ) );
		}
	}

	public function testNormalizeStatusPassesAllThrough(): void {
		$this->assertSame( 'all', SuggestFilters::normalizeStatus( 'all' ) );
	}

	public function testNormalizeStatusFallsBackOnGarbage(): void {
		$this->assertSame( 'new', SuggestFilters::normalizeStatus( 'bogus' ) );
		$this->assertSame( 'new', SuggestFilters::normalizeStatus( null ) );
		$this->assertSame( 'reviewed', SuggestFilters::normalizeStatus( 'bogus', 'reviewed' ) );
	}

	public function testNormalizeSort(): void {
		$this->assertSame( 'newest', SuggestFilters::normalizeSort( null ) );
		$this->assertSame( 'oldest', SuggestFilters::normalizeSort( 'oldest' ) );
		$this->assertSame( 'newest', SuggestFilters::normalizeSort( 'sideways' ) );
	}

	public function testSanitizeSearchTrimsAndTruncates(): void {
		$this->assertSame( '', SuggestFilters::sanitizeSearch( null ) );
		$this->assertSame( '', SuggestFilters::sanitizeSearch( '   ' ) );
		$this->assertSame( 'hello', SuggestFilters::sanitizeSearch( '  hello  ' ) );

		$long = str_repeat( 'x', SuggestFilters::MAX_SEARCH_LENGTH + 50 );
		$this->assertSame(
			SuggestFilters::MAX_SEARCH_LENGTH,
			mb_strlen( SuggestFilters::sanitizeSearch( $long ) )
		);
	}

	/**
	 * LIKE wildcards are escaped by the database layer, so stripping them
	 * here would only break legitimate searches such as "50%".
	 */
	public function testSanitizeSearchKeepsLikeWildcards(): void {
		$this->assertSame( '50%', SuggestFilters::sanitizeSearch( '50%' ) );
		$this->assertSame( 'under_score', SuggestFilters::sanitizeSearch( 'under_score' ) );
	}

	public function testSanitizeIdentifierRejectsOverlongValues(): void {
		$this->assertSame( '', SuggestFilters::sanitizeIdentifier( null ) );
		$this->assertSame( '', SuggestFilters::sanitizeIdentifier( '  ' ) );
		$this->assertSame( 'Parishes', SuggestFilters::sanitizeIdentifier( ' Parishes ' ) );
		$this->assertSame( '', SuggestFilters::sanitizeIdentifier( str_repeat( 'a', 201 ) ) );
	}

	public function testProcessActionsExcludesNew(): void {
		$actions = SuggestFilters::processActions();
		$this->assertNotContains( 'new', $actions, 'A reviewer cannot move an item back to new' );
		$this->assertContains( 'reviewed', $actions );
		$this->assertContains( 'actioned', $actions );
		$this->assertContains( 'dismissed', $actions );
	}

	public function testStatusUpdateOptsOmitsEmptyNote(): void {
		$this->assertSame( [], SuggestFilters::statusUpdateOpts( null ) );
		$this->assertSame( [], SuggestFilters::statusUpdateOpts( '   ' ) );
		$this->assertSame( [ 'workNote' => 'checked' ], SuggestFilters::statusUpdateOpts( 'checked' ) );
	}

	public function testWithOffsetOmitsFirstPage(): void {
		$this->assertSame( [ 'status' => 'new' ], SuggestFilters::withOffset( [ 'status' => 'new' ], 0 ) );
		$this->assertSame(
			[ 'status' => 'new', 'offset' => 50 ],
			SuggestFilters::withOffset( [ 'status' => 'new' ], 50 )
		);
	}

	public function testClampOffsetSnapsPastEndOntoLastPage(): void {
		$this->assertSame( 0, SuggestFilters::clampOffset( 0, 0, 50 ) );
		$this->assertSame( 0, SuggestFilters::clampOffset( 100, 0, 50 ) );
		$this->assertSame( 50, SuggestFilters::clampOffset( 50, 120, 50 ) );
		// offset past the end snaps back to the final page, not past it
		$this->assertSame( 100, SuggestFilters::clampOffset( 500, 120, 50 ) );
		$this->assertSame( 0, SuggestFilters::clampOffset( -10, 120, 50 ) );
	}

	/**
	 * Per-row dashboard buttons encode the id in the submit value so a single
	 * form wrapping every row cannot apply the last hidden sps_id instead of
	 * the row that was clicked.
	 */
	public function testParseRowActionReadsIdAndStatus(): void {
		$this->assertSame(
			[ 'id' => 42, 'status' => 'actioned' ],
			SuggestFilters::parseRowAction( '42:actioned' )
		);
		$this->assertSame(
			[ 'id' => 7, 'status' => 'reviewed' ],
			SuggestFilters::parseRowAction( '7:reviewed' )
		);
		$this->assertSame(
			[ 'id' => 1, 'status' => 'dismissed' ],
			SuggestFilters::parseRowAction( '1:dismissed' )
		);
	}

	public function testParseRowActionRejectsGarbage(): void {
		$this->assertNull( SuggestFilters::parseRowAction( null ) );
		$this->assertNull( SuggestFilters::parseRowAction( '' ) );
		$this->assertNull( SuggestFilters::parseRowAction( 'actioned' ) );
		$this->assertNull( SuggestFilters::parseRowAction( '42' ) );
		$this->assertNull( SuggestFilters::parseRowAction( '42:new' ), 'cannot move back to new' );
		$this->assertNull( SuggestFilters::parseRowAction( '0:actioned' ) );
		$this->assertNull( SuggestFilters::parseRowAction( '-3:actioned' ) );
		$this->assertNull( SuggestFilters::parseRowAction( '42:actioned:extra' ) );
		$this->assertNull( SuggestFilters::parseRowAction( 'abc:actioned' ) );
	}
}
