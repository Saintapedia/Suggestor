<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Integration;

use ApiTestCase;
use ApiUsageException;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\MediaWikiServices;

/**
 * Gate ordering and refusal behaviour of action=saintapediasuggest.
 *
 * These assertions are about the order the API says no in, which is the part
 * that carries the security properties: a malformed request must be refused
 * before it can burn a one-time captcha token, and the stored snapshot must
 * come from Cargo rather than from the request.
 *
 * Captcha is switched off for these tests ($wgSaintapediaSuggestRequireCaptcha
 * = false); CaptchaGateConfigTest covers the policy itself.
 *
 * @group Database
 * @group medium
 * @group SaintapediaSuggest
 * @covers \MediaWiki\Extension\SaintapediaSuggest\Api\ApiSaintapediaSuggestSubmit
 */
class ApiSaintapediaSuggestSubmitTest extends ApiTestCase {

	use CargoFixtureTrait;

	private const TABLE = self::FIXTURE_TABLE;

	/** Set by existingPageId() so the Cargo row points at a real page. */
	private ?int $pageId = null;

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed[] = 'sps_suggestion';
		$this->tablesUsed[] = 'sps_suggestion_log';
		$this->tablesUsed[] = 'page';
		$this->tablesUsed[] = 'ipblocks';
		$this->tablesUsed[] = 'cargo_tables';
		$this->tablesUsed[] = 'cargo_pages';

		$this->overrideConfigValues( [
			'SaintapediaSuggestRequireCaptcha' => false,
			'SaintapediaSuggestEnabled'        => true,
			'SaintapediaSuggestMode'           => 'public',
			'SaintapediaSuggestNamespaces'     => [ NS_MAIN ],
			'SaintapediaSuggestRateLimit'      => 50,
			'SaintapediaSuggestMaxValueLength' => 500,
			'SaintapediaSuggestTables'         => [ self::TABLE => [ 'Name', 'City', 'Location' ] ],
			'SaintapediaSuggestMergeDuplicates' => true,
		] );

		// A real Cargo row for a real page, so the submit path can snapshot a
		// current value instead of bailing out before it gets there.
		$this->createCargoFixture( $this->existingPageId() );
	}

	protected function tearDown(): void {
		$this->dropCargoFixture();
		parent::tearDown();
	}

	private function existingPageId(): int {
		$this->pageId ??= $this->getExistingTestPage( 'SaintapediaSuggest test page' )->getId();
		return $this->pageId;
	}

	/**
	 * @param array<string,string|int> $params
	 */
	private function submit( array $params ): array {
		return $this->doApiRequestWithToken(
			$params + [ 'action' => 'saintapediasuggest' ]
		);
	}

	/**
	 * Assert the submit was refused with a specific API error code.
	 *
	 * Uses ApiTestCase::apiExceptionHasCode(), which is the framework's own
	 * static helper — an identically named instance method here would
	 * collide with it fatally at class-load time.
	 */
	private function assertRefusedWith( string $code, array $params ): void {
		try {
			$this->submit( $params );
			$this->fail( "Expected the API to refuse with '$code'." );
		} catch ( ApiUsageException $e ) {
			$this->assertTrue(
				self::apiExceptionHasCode( $e, $code ),
				"Expected error code '$code', got: " . $e->getMessage()
			);
		}
	}

	public function testTokenIsRequired(): void {
		$this->expectException( ApiUsageException::class );
		$this->doApiRequest( [
			'action'         => 'saintapediasuggest',
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => '555-0100',
		] );
	}

	public function testNonexistentPageIsRefused(): void {
		$this->assertRefusedWith( 'invalidtitle', [
			'pageid'         => 999999999,
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => '555-0100',
		] );
	}

	public function testDisabledWikiRefusesBeforeAnythingElse(): void {
		$this->overrideConfigValue( 'SaintapediaSuggestEnabled', false );
		$this->assertRefusedWith( 'sps-disabled', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => '555-0100',
		] );
	}

	public function testNamespaceOutsideTheAllowedSetIsRefused(): void {
		$this->overrideConfigValue( 'SaintapediaSuggestNamespaces', [ NS_PROJECT ] );
		$this->assertRefusedWith( 'sps-namespace', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => '555-0100',
		] );
	}

	public function testReservedCargoFieldIsRefused(): void {
		$this->assertRefusedWith( 'sps-nofield', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => '_pageID',
			'suggestedvalue' => '1',
		] );
	}

	public function testFieldOutsideTheAllowListIsRefused(): void {
		$this->assertRefusedWith( 'sps-nofield', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Founded',
			'suggestedvalue' => 'x',
		] );
	}

	public function testTableOutsideTheAllowListIsRefused(): void {
		$this->assertRefusedWith( 'sps-nofield', [
			'pageid'         => $this->existingPageId(),
			'table'          => 'NotAllowListedTable',
			'field'          => 'Name',
			'suggestedvalue' => 'x',
		] );
	}

	/**
	 * The allow-list is checked before the captcha, so a request that could
	 * never succeed cannot consume a reader's one-time hCaptcha token.
	 */
	public function testAllowListIsCheckedBeforeTheCaptcha(): void {
		$this->overrideConfigValue( 'SaintapediaSuggestRequireCaptcha', true );
		$this->assertRefusedWith( 'sps-nofield', [
			'pageid'         => $this->existingPageId(),
			'table'          => 'NotAllowListedTable',
			'field'          => 'Name',
			'suggestedvalue' => 'x',
		] );
	}

	public function testEmptySuggestedValueIsRefused(): void {
		$this->expectException( ApiUsageException::class );
		$this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => '',
		] );
	}

	/**
	 * Blocked users cannot submit, including under a partial block — the
	 * module denies on any block, matching core's write-API convention.
	 */
	public function testBlockedUserIsRefused(): void {
		$blocked = $this->getMutableTestUser()->getUser();
		$block = new DatabaseBlock( [
			'address' => $blocked->getName(),
			'by'      => $this->getTestSysop()->getUser(),
			'reason'  => 'SaintapediaSuggest test',
			'expiry'  => 'infinity',
		] );
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlock( $block );

		$this->expectException( ApiUsageException::class );
		$this->doApiRequestWithToken(
			[
				'action'         => 'saintapediasuggest',
				'pageid'         => $this->existingPageId(),
				'table'          => self::TABLE,
				'field'          => 'Name',
				'suggestedvalue' => '555-0100',
			],
			null,
			$blocked
		);
	}

	/**
	 * The public API must never echo anything back beyond a result and an id
	 * — this endpoint is readable by anyone.
	 */
	public function testResponseCarriesOnlyResultAndId(): void {
		[ $result ] = $this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => 'St. Corrected',
			'email'          => 'reader@example.org',
		] );

		$this->assertSame( [ 'result', 'id' ], array_keys( $result['saintapediasuggest'] ) );
		$this->assertSame( 'success', $result['saintapediasuggest']['result'] );
		$this->assertStringNotContainsString( 'reader@example.org', json_encode( $result ) );
	}

	/**
	 * The stored snapshot must be re-read from Cargo, never taken from the
	 * request — otherwise a submitter could fabricate the before-state a
	 * reviewer sees.
	 */
	public function testCurrentValueIsSnapshottedFromCargo(): void {
		[ $result ] = $this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => 'St. Corrected',
		] );

		$store = MediaWikiServices::getInstance()
			->getService( 'SaintapediaSuggest.SuggestionStore' );
		$row = $store->getById( (int)$result['saintapediasuggest']['id'] );

		$this->assertSame( 'St. Fixture', (string)$row->sg_current_value );
		$this->assertSame( 'St. Corrected', (string)$row->sg_suggested_value );
		$this->assertSame( self::TABLE, (string)$row->sg_cargo_table );
	}

	/**
	 * A Coordinates field has no column under its own name; the submit path
	 * must still resolve its current value from the `__full` column.
	 */
	public function testCoordinatesFieldCanBeSuggested(): void {
		[ $result ] = $this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Location',
			'suggestedvalue' => '33.5700, -86.7300',
		] );

		$store = MediaWikiServices::getInstance()
			->getService( 'SaintapediaSuggest.SuggestionStore' );
		$row = $store->getById( (int)$result['saintapediasuggest']['id'] );

		$this->assertSame( '33.56557, -86.72564', (string)$row->sg_current_value );
	}

	/* ------------------------------------------------------ multi-row rows */

	public function testRowIdMustBelongToThePageAndTable(): void {
		$this->createMultiRowCargoFixture( $this->existingPageId() );
		$this->overrideConfigValue( 'SaintapediaSuggestTables',
			[ self::FIXTURE_MULTI_TABLE => [ 'EventYear' ] ] );

		$this->assertRefusedWith( 'sps-norow', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::FIXTURE_MULTI_TABLE,
			'field'          => 'EventYear',
			'rowid'          => 99,
			'suggestedvalue' => '1800',
		] );
	}

	/**
	 * Omitting rowid on a multi-row table used to snapshot the first row.
	 * That is ambiguous, so it is now refused the same as a bogus id.
	 */
	public function testOmittingRowIdOnAMultiRowTableIsRefused(): void {
		$this->createMultiRowCargoFixture( $this->existingPageId() );
		$this->overrideConfigValue( 'SaintapediaSuggestTables',
			[ self::FIXTURE_MULTI_TABLE => [ 'EventYear' ] ] );

		$this->assertRefusedWith( 'sps-norow', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::FIXTURE_MULTI_TABLE,
			'field'          => 'EventYear',
			'suggestedvalue' => '1800',
		] );
	}

	/**
	 * A 0.2.x client that never sent rowid still works against a one-row
	 * table, and the omitted id is stored so freshness can compare later.
	 */
	public function testOmittingRowIdOnASingleRowTableFillsItIn(): void {
		[ $result ] = $this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => 'St. Filled In',
		] );

		$store = MediaWikiServices::getInstance()
			->getService( 'SaintapediaSuggest.SuggestionStore' );
		$row = $store->getById( (int)$result['saintapediasuggest']['id'] );

		$this->assertSame( 1, (int)$row->sg_cargo_row_id );
	}

	/**
	 * The snapshot must come from the row the reader named, not from whichever
	 * row the database returns first — that was the multi-row bug.
	 */
	public function testSnapshotComesFromTheNamedRow(): void {
		$this->createMultiRowCargoFixture( $this->existingPageId() );
		$this->overrideConfigValue( 'SaintapediaSuggestTables',
			[ self::FIXTURE_MULTI_TABLE => [ 'EventYear', 'LocationTitle' ] ] );

		[ $result ] = $this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::FIXTURE_MULTI_TABLE,
			'field'          => 'EventYear',
			'rowid'          => 3,
			'suggestedvalue' => '1795',
		] );

		$store = MediaWikiServices::getInstance()
			->getService( 'SaintapediaSuggest.SuggestionStore' );
		$row = $store->getById( (int)$result['saintapediasuggest']['id'] );

		$this->assertSame( '1794', (string)$row->sg_current_value, 'row 3, not row 1' );
		$this->assertSame( 3, (int)$row->sg_cargo_row_id );
		$this->assertSame(
			'Seton family home site (State Street area)',
			(string)$row->sg_cargo_row_label
		);
	}

	public function testCorrectionsToDifferentRowsStaySeparate(): void {
		$this->createMultiRowCargoFixture( $this->existingPageId() );
		$this->overrideConfigValue( 'SaintapediaSuggestTables',
			[ self::FIXTURE_MULTI_TABLE => [ 'SiteType' ] ] );

		foreach ( [ 1, 2 ] as $rowId ) {
			$this->submit( [
				'pageid'         => $this->existingPageId(),
				'table'          => self::FIXTURE_MULTI_TABLE,
				'field'          => 'SiteType',
				'rowid'          => $rowId,
				'suggestedvalue' => 'Basilica',
			] );
		}

		$store = MediaWikiServices::getInstance()
			->getService( 'SaintapediaSuggest.SuggestionStore' );
		$this->assertSame(
			2,
			$store->countDashboard( [ 'status' => 'all' ] ),
			'Same value on two different rows is two problems, not one'
		);
	}

	public function testResubmittingTheStoredValueIsRefused(): void {
		$this->assertRefusedWith( 'sps-unchanged', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => 'St. Fixture',
		] );
	}

	/**
	 * The unchanged-value check uses SuggestionMerger::valuesMatch(), the
	 * same normalized comparison as duplicate folding and the freshness
	 * check -- not a raw case-sensitive equality. A prior version of this
	 * check would have let this through to the queue, only for the
	 * dashboard's freshness check to immediately flag it "already applied".
	 */
	public function testResubmittingTheStoredValueWithDifferentCaseIsRefused(): void {
		$this->assertRefusedWith( 'sps-unchanged', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => 'st. fixture',
		] );
	}

	/**
	 * A second reader proposing the same value joins the first as a duplicate
	 * rather than opening a second queue item.
	 */
	public function testRepeatReportIsFoldedIntoTheFirst(): void {
		[ $first ] = $this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => 'St. Corrected',
		] );
		[ $second ] = $this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Name',
			'suggestedvalue' => '  st.   CORRECTED ',
		] );

		$store = MediaWikiServices::getInstance()
			->getService( 'SaintapediaSuggest.SuggestionStore' );
		$firstId = (int)$first['saintapediasuggest']['id'];
		$secondId = (int)$second['saintapediasuggest']['id'];

		$this->assertSame( $firstId, (int)$store->getById( $secondId )->sg_duplicate_of );
		$this->assertSame( 1, (int)$store->getById( $firstId )->sg_duplicate_count );
		$this->assertSame( 1, $store->countDashboard( [ 'status' => 'all' ] ) );
	}
}
