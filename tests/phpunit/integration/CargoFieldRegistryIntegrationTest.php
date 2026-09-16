<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Integration;

use CargoUtils;
use ExtensionRegistry;
use HashConfig;
use MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use Title;

/**
 * CargoFieldRegistry against a real Cargo schema.
 *
 * The fixture is built here rather than read from whatever the wiki happens to
 * contain. MediaWiki's test framework clones tables into a prefixed test
 * database, so any pre-existing Cargo content is invisible to these tests —
 * depending on it produced a suite that silently skipped everything.
 *
 * The fixture deliberately covers each way Cargo lays a field out:
 *
 *   Name      String        -> column `Name`
 *   City      Page          -> column `City`
 *   Founded   Date          -> columns `Founded` and `Founded__precision`
 *   Aliases   List of String-> column `Aliases__full` and a helper table
 *   Location  Coordinates   -> columns `Location__full`, `__lat`, `__lon`
 *
 * The last two are the ones that matter: they have NO column under their own
 * name, so selecting one by its bare schema name raises "Unknown column" and
 * takes every suggestion for the whole table down with it.
 *
 * @group Database
 * @group SaintapediaSuggest
 * @covers \MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry
 */
class CargoFieldRegistryIntegrationTest extends MediaWikiIntegrationTestCase {

	use CargoFixtureTrait;

	private const TABLE = self::FIXTURE_TABLE;

	private const PAGE_ID = self::FIXTURE_PAGE_ID;

	protected function setUp(): void {
		parent::setUp();

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Cargo' ) ) {
			$this->markTestSkipped( 'Cargo is not installed on this wiki.' );
		}

		// cargo_tables / cargo_pages live in the wiki database, so the test
		// framework clones them and rolls them back for us.
		$this->tablesUsed[] = 'cargo_tables';
		$this->tablesUsed[] = 'cargo_pages';

		$this->createCargoFixture();
	}

	protected function tearDown(): void {
		$this->dropCargoFixture();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $tables
	 * @param array<string,mixed> $extra
	 */
	private function registry( array $tables, array $extra = [] ): CargoFieldRegistry {
		return new CargoFieldRegistry(
			// Mirrors the full set of defaults extension.json supplies, so a
			// missing key here cannot be mistaken for a code failure.
			new HashConfig( $extra + [
				'SaintapediaSuggestTables'          => $tables,
				'SaintapediaSuggestMaxFields'       => 40,
				'SaintapediaSuggestMaxValueLength'  => 500,
				'SaintapediaSuggestMaxRowsPerTable' => 25,
				'SaintapediaSuggestRowLabelField'   => [],
			] ),
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
	}

	private function allowAll(): CargoFieldRegistry {
		return $this->registry( [ self::TABLE => '*' ] );
	}

	/**
	 * Creates MediaWiki:SaintapediaSuggest-tables with $text. A real save, so
	 * Hooks::onPageSaveComplete() fires and invalidates SuggestWikiConfig's
	 * WAN cache the same way a real admin edit would — no manual cache-clear
	 * needed in the tests below. The page starts out missing in every test
	 * (each test runs in its own rolled-back transaction), so the
	 * "missing/empty page" case just means never calling this.
	 */
	private function setTablesPage( string $text ): void {
		$this->editPage( Title::makeTitleSafe( NS_MEDIAWIKI, 'SaintapediaSuggest-tables' ), $text );
	}

	/**
	 * #6 regression guard: a page with content that names no real, currently
	 * existing Cargo table must fall back to the PHP allow-list rather than
	 * disabling every suggestion wiki-wide. See CargoFieldRegistry::getAllowList().
	 */
	public function testAllowListFallsBackToPhpWhenNoOverlayLineNamesARealTable(): void {
		$this->setTablesPage( "NotARealTable: SomeField\nAlsoNotReal: *" );

		$registry = $this->registry( [ self::TABLE => [ 'Name', 'City' ] ] );

		$this->assertSame(
			[ self::TABLE ],
			$registry->getTablesForPage( self::PAGE_ID ),
			'A junk wiki override must not make the PHP-configured table disappear'
		);
		$this->assertSame( [ 'Name', 'City' ], $registry->getAllowedFields( self::TABLE ) );
	}

	/**
	 * Same guard, missing-page case: no MediaWiki:SaintapediaSuggest-tables
	 * page at all must use the PHP list, same as an unrecognized one.
	 */
	public function testAllowListUsesPhpValueWhenOverlayPageIsMissing(): void {
		$registry = $this->registry( [ self::TABLE => '*' ] );

		$this->assertSame(
			[ self::TABLE ],
			$registry->getTablesForPage( self::PAGE_ID )
		);
	}

	/**
	 * A wiki page naming a real table wins over the PHP list, even when the
	 * PHP list configures something else entirely — the whole point of
	 * SaintapediaSuggestTablesPage is to let an admin change this without a
	 * deploy.
	 */
	public function testAllowListOverlayWinsOverPhpValueWhenItNamesARealTable(): void {
		$this->setTablesPage( self::TABLE . ': Name, City' );

		// PHP list points at a table that does not exist -- if the overlay
		// were ignored, getTablesForPage() would come back empty.
		$registry = $this->registry( [ 'SomeOtherConfiguredTable' => '*' ] );

		$this->assertSame( [ self::TABLE ], $registry->getTablesForPage( self::PAGE_ID ) );
		$this->assertSame( [ 'Name', 'City' ], $registry->getAllowedFields( self::TABLE ) );
	}

	/**
	 * An overlay naming a real table, but only fields that do not exist in
	 * its schema, is a deliberate "nothing from this table" configuration
	 * (a typo'd field, or an admin narrowing access down to zero fields on
	 * purpose) -- distinct from #6's "the table name itself is junk" case.
	 * The table name still matches a real Cargo table, so this must NOT
	 * fall back to the PHP list; it should keep the overlay and end up with
	 * no allowed fields for that table via the ordinary schema-intersection
	 * path in getAllowedFields(), not via the fallback.
	 */
	public function testAllowListOverlayNamingARealTableWithNoRealFieldsIsKeptNotFallenBack(): void {
		$this->setTablesPage( self::TABLE . ': NoSuchFieldAtAll' );

		$registry = $this->registry( [ self::TABLE => [ 'Name', 'City' ] ] );

		// The table is still recognized (overlay wins, not a fallback)...
		$this->assertSame( [ self::TABLE ], $registry->getTablesForPage( self::PAGE_ID ) );
		// ...but nothing from it is actually suggestable, because the only
		// field the overlay named does not exist in the live schema.
		$this->assertSame( [], $registry->getAllowedFields( self::TABLE ) );
	}

	public function testFixtureIsVisibleToCargo(): void {
		$this->assertContains( self::TABLE, CargoUtils::getTables() );
		$schemas = CargoUtils::getTableSchemas( [ self::TABLE ] );
		$this->assertArrayHasKey( self::TABLE, $schemas );
		$this->assertSame(
			[ 'Name', 'City', 'Founded', 'Aliases', 'Location' ],
			array_keys( $schemas[self::TABLE]->mFieldDescriptions )
		);
	}

	/**
	 * HashConfig::get() throws on an undefined option. Optional knobs must
	 * fall back to their defaults rather than taking the whole widget down on
	 * a Config that does not define them.
	 */
	public function testOptionalConfigMayBeAbsent(): void {
		$registry = new CargoFieldRegistry(
			new HashConfig( [
				'SaintapediaSuggestTables'         => [ self::TABLE => '*' ],
				'SaintapediaSuggestMaxFields'      => 40,
				'SaintapediaSuggestMaxValueLength' => 500,
				// MaxRowsPerTable and RowLabelField deliberately omitted.
			] ),
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
		$fields = $registry->getSuggestableFields( self::PAGE_ID );
		$this->assertCount( 5, $fields );
		$this->assertSame( 'St. Fixture', $fields[0]['rowLabel'] );
	}

	public function testCargoIsDetected(): void {
		$this->assertTrue( $this->registry( [] )->isCargoAvailable() );
	}

	public function testEmptyAllowListExposesNothing(): void {
		$registry = $this->registry( [] );
		$this->assertSame( [], $registry->getTablesForPage( self::PAGE_ID ) );
		$this->assertSame( [], $registry->getSuggestableFields( self::PAGE_ID ) );
		$this->assertFalse( $registry->isAllowed( self::TABLE, 'Name' ) );
	}

	public function testAllowListedTableIsFoundForItsPage(): void {
		$this->assertSame( [ self::TABLE ], $this->allowAll()->getTablesForPage( self::PAGE_ID ) );
	}

	public function testWildcardExposesEveryNonInternalField(): void {
		$this->assertSame(
			[ 'Name', 'City', 'Founded', 'Aliases', 'Location' ],
			$this->allowAll()->getAllowedFields( self::TABLE )
		);
	}

	public function testExplicitAllowListLimitsToNamedFields(): void {
		$registry = $this->registry( [ self::TABLE => [ 'Name', 'Location' ] ] );
		$this->assertSame( [ 'Name', 'Location' ], $registry->getAllowedFields( self::TABLE ) );
		$this->assertTrue( $registry->isAllowed( self::TABLE, 'Name' ) );
		$this->assertFalse( $registry->isAllowed( self::TABLE, 'City' ) );
	}

	/**
	 * The point of the allow-list: Cargo's internal bookkeeping columns are
	 * never exposed, not even under the '*' wildcard.
	 */
	public function testReservedColumnsAreNeverExposed(): void {
		$registry = $this->allowAll();

		foreach ( $registry->getAllowedFields( self::TABLE ) as $field ) {
			$this->assertStringStartsNotWith( '_', $field );
		}
		foreach ( [ '_pageID', '_pageName', '_ID', '_pageNamespace' ] as $reserved ) {
			$this->assertFalse( $registry->isAllowed( self::TABLE, $reserved ), $reserved );
			$this->assertNull( $registry->getCurrentValue( self::PAGE_ID, self::TABLE, $reserved ) );
		}
	}

	public function testReservedColumnsCannotBeAllowListedExplicitly(): void {
		$registry = $this->registry( [ self::TABLE => [ '_pageID', 'Name' ] ] );
		$this->assertSame( [ 'Name' ], $registry->getAllowedFields( self::TABLE ) );
		$this->assertFalse( $registry->isAllowed( self::TABLE, '_pageID' ) );
	}

	public function testUnknownTableAndFieldAreRefused(): void {
		$registry = $this->allowAll();
		$this->assertFalse( $registry->isAllowed( 'NoSuchCargoTable', 'Whatever' ) );
		$this->assertFalse( $registry->isAllowed( self::TABLE, 'NoSuchFieldAtAll' ) );
		$this->assertNull(
			$registry->getCurrentValue( self::PAGE_ID, self::TABLE, 'NoSuchFieldAtAll' )
		);
	}

	/**
	 * A field allow-listed in config but since removed from the template must
	 * drop out silently rather than reaching the database as an identifier.
	 */
	public function testFieldNotInTheLiveSchemaIsDropped(): void {
		$registry = $this->registry( [ self::TABLE => [ 'Name', 'RemovedFromTemplate' ] ] );
		$this->assertSame( [ 'Name' ], $registry->getAllowedFields( self::TABLE ) );
	}

	/**
	 * The regression guard. Every field the registry offers must actually be
	 * readable — list and Coordinates fields live in `__full` columns and have
	 * no column under their own name.
	 */
	public function testEverySuggestableFieldIsActuallyReadable(): void {
		$registry = $this->allowAll();
		$fields = $registry->getSuggestableFields( self::PAGE_ID );
		$this->assertCount( 5, $fields );

		foreach ( $fields as $entry ) {
			$this->assertIsString( $entry['value'], "{$entry['field']} returned a non-string" );
			$this->assertSame(
				$entry['value'],
				$registry->getCurrentValue( self::PAGE_ID, self::TABLE, $entry['field'] ),
				"getCurrentValue disagrees with getSuggestableFields for {$entry['field']}"
			);
		}
	}

	public function testValuesComeFromTheRightPhysicalColumns(): void {
		$byField = [];
		foreach ( $this->allowAll()->getSuggestableFields( self::PAGE_ID ) as $entry ) {
			$byField[$entry['field']] = $entry;
		}

		$this->assertSame( 'St. Fixture', $byField['Name']['value'] );
		$this->assertSame( 'Birmingham, AL', $byField['City']['value'] );
		$this->assertSame( '1908-01-01', $byField['Founded']['value'] );
		// Read from Aliases__full, not a (nonexistent) Aliases column.
		$this->assertSame( 'St Fixture,Saint Fixture', $byField['Aliases']['value'] );
		// Read from Location__full, not Location / __lat / __lon.
		$this->assertSame( '33.56557, -86.72564', $byField['Location']['value'] );
	}

	public function testTypeAndListFlagsAreReported(): void {
		$byField = [];
		foreach ( $this->allowAll()->getSuggestableFields( self::PAGE_ID ) as $entry ) {
			$byField[$entry['field']] = $entry;
		}

		$this->assertTrue( $byField['Aliases']['isList'] );
		$this->assertFalse( $byField['Location']['isList'] );
		$this->assertSame( 'Coordinates', $byField['Location']['type'] );
		$this->assertSame( 'String', $byField['Name']['type'] );
	}

	public function testPhysicalColumnMatchesCargosLayout(): void {
		foreach ( $this->allowAll()->getSuggestableFields( self::PAGE_ID ) as $entry ) {
			$expected = ( $entry['isList'] || $entry['type'] === 'Coordinates' )
				? $entry['field'] . '__full'
				: $entry['field'];
			$this->assertSame(
				$expected,
				CargoFieldRegistry::physicalColumn(
					$entry['field'],
					(object)[ 'mIsList' => $entry['isList'], 'mType' => $entry['type'] ]
				)
			);
		}
	}

	/* ------------------------------------------------- multi-row behaviour */

	private function multiRegistry(): CargoFieldRegistry {
		$this->createMultiRowCargoFixture();
		return $this->registry( [ self::FIXTURE_MULTI_TABLE => '*' ] );
	}

	/**
	 * The bug this exists to prevent: three rows collapsing into one set of
	 * values, so a reader correcting the third sighting was silently offered
	 * — and attributed to — the first.
	 */
	public function testEveryRowIsOfferedSeparately(): void {
		$fields = $this->multiRegistry()->getSuggestableFields( self::FIXTURE_PAGE_ID );

		// 3 rows x 3 fields
		$this->assertCount( 9, $fields );
		$this->assertSame( [ 1, 2, 3 ], array_values( array_unique(
			array_column( $fields, 'rowId' )
		) ) );

		$years = [];
		foreach ( $fields as $f ) {
			if ( $f['field'] === 'EventYear' ) {
				$years[ $f['rowId'] ] = $f['value'];
			}
		}
		$this->assertSame( [ 1 => '1809', 2 => '1805', 3 => '1794' ], $years );
	}

	public function testRowsAreLabelledByTheirFirstAllowListedValue(): void {
		$byRow = [];
		foreach ( $this->multiRegistry()->getSuggestableFields( self::FIXTURE_PAGE_ID ) as $f ) {
			$byRow[ $f['rowId'] ] = $f['rowLabel'];
		}
		$this->assertSame( 'National Shrine of Saint Elizabeth Ann Seton', $byRow[1] );
		$this->assertSame( 'Seton family home site (State Street area)', $byRow[3] );
	}

	public function testRowLabelFieldOverrideIsHonoured(): void {
		$this->createMultiRowCargoFixture();
		$registry = $this->registry(
			[ self::FIXTURE_MULTI_TABLE => '*' ],
			[ 'SaintapediaSuggestRowLabelField' => [ self::FIXTURE_MULTI_TABLE => 'SiteType' ] ]
		);
		$byRow = [];
		foreach ( $registry->getSuggestableFields( self::FIXTURE_PAGE_ID ) as $f ) {
			$byRow[ $f['rowId'] ] = $f['rowLabel'];
		}
		$this->assertSame( [ 1 => 'Shrine', 2 => 'Church', 3 => 'Home' ], $byRow );
	}

	public function testRowCountIsReportedOnEveryEntry(): void {
		foreach ( $this->multiRegistry()->getSuggestableFields( self::FIXTURE_PAGE_ID ) as $f ) {
			$this->assertSame( 3, $f['rowCount'] );
		}
	}

	public function testSingleRowTableStillReportsOneRow(): void {
		foreach ( $this->allowAll()->getSuggestableFields( self::PAGE_ID ) as $f ) {
			$this->assertSame( 1, $f['rowCount'] );
			$this->assertSame( 1, $f['rowId'] );
		}
	}

	public function testCurrentValueIsReadFromTheNamedRow(): void {
		$registry = $this->multiRegistry();
		$t = self::FIXTURE_MULTI_TABLE;

		$this->assertSame( '1809', $registry->getCurrentValue( self::FIXTURE_PAGE_ID, $t, 'EventYear', 1 ) );
		$this->assertSame( '1805', $registry->getCurrentValue( self::FIXTURE_PAGE_ID, $t, 'EventYear', 2 ) );
		$this->assertSame( '1794', $registry->getCurrentValue( self::FIXTURE_PAGE_ID, $t, 'EventYear', 3 ) );
	}

	public function testUnknownRowIdIsRefused(): void {
		$registry = $this->multiRegistry();
		$t = self::FIXTURE_MULTI_TABLE;

		$this->assertFalse( $registry->isValidRow( self::FIXTURE_PAGE_ID, $t, 99 ) );
		$this->assertNull( $registry->getCurrentValue( self::FIXTURE_PAGE_ID, $t, 'EventYear', 99 ) );
		foreach ( [ 1, 2, 3 ] as $id ) {
			$this->assertTrue( $registry->isValidRow( self::FIXTURE_PAGE_ID, $t, $id ) );
		}
		$this->assertSame( [ 1, 2, 3 ], $registry->getRowIds( self::FIXTURE_PAGE_ID, $t ) );
	}

	public function testRowsFromAnotherPageAreNotValid(): void {
		$registry = $this->multiRegistry();
		$this->assertFalse(
			$registry->isValidRow( self::FIXTURE_PAGE_ID + 1, self::FIXTURE_MULTI_TABLE, 1 )
		);
	}

	public function testGetRowLabelNamesTheRowThatWasRead(): void {
		$registry = $this->multiRegistry();
		$this->assertSame(
			'Seton family home site (State Street area)',
			$registry->getRowLabel( self::FIXTURE_PAGE_ID, self::FIXTURE_MULTI_TABLE, 'EventYear', 3 )
		);
	}

	public function testMaxRowsPerTableCapsTheOffer(): void {
		$this->createMultiRowCargoFixture();
		$registry = $this->registry(
			[ self::FIXTURE_MULTI_TABLE => '*' ],
			[ 'SaintapediaSuggestMaxRowsPerTable' => 2 ]
		);
		$rowIds = array_unique( array_column(
			$registry->getSuggestableFields( self::FIXTURE_PAGE_ID ), 'rowId'
		) );
		$this->assertCount( 2, $rowIds );
	}

	/**
	 * A page in two Cargo tables must offer both, which is what lets the
	 * picker group by table.
	 */
	public function testFieldsFromSeveralTablesAreOfferedTogether(): void {
		$this->createMultiRowCargoFixture();
		$registry = $this->registry( [
			self::FIXTURE_TABLE       => '*',
			self::FIXTURE_MULTI_TABLE => '*',
		] );

		// Sorted so the assertion does not depend on cargo_pages row order.
		$expected = [ self::FIXTURE_TABLE, self::FIXTURE_MULTI_TABLE ];
		sort( $expected );

		$tables = array_values( array_unique( array_column(
			$registry->getSuggestableFields( self::FIXTURE_PAGE_ID ), 'table'
		) ) );
		sort( $tables );
		$this->assertSame( $expected, $tables );

		$fromPage = $registry->getTablesForPage( self::FIXTURE_PAGE_ID );
		sort( $fromPage );
		$this->assertSame( $expected, $fromPage );
	}

	/* ------------------------------------------ batch lookup for freshness */

	public function testGetValuesForPageTableReturnsEveryRowKeyedById(): void {
		$this->createMultiRowCargoFixture();
		$registry = $this->registry( [ self::FIXTURE_MULTI_TABLE => '*' ] );

		$values = $registry->getValuesForPageTable( self::FIXTURE_PAGE_ID, self::FIXTURE_MULTI_TABLE );
		$this->assertSame( [ 1, 2, 3 ], array_keys( $values ) );
		$this->assertSame( '1794', $values[3]['EventYear'] );
		$this->assertSame( 'Church', $values[2]['SiteType'] );
	}

	/**
	 * The batch reader must agree with the single-value reader, including for
	 * fields stored in a `__full` column.
	 */
	public function testBatchLookupAgreesWithGetCurrentValue(): void {
		$registry = $this->allowAll();
		$values = $registry->getValuesForPageTable( self::PAGE_ID, self::TABLE );
		$this->assertCount( 1, $values );

		foreach ( reset( $values ) as $field => $value ) {
			$this->assertSame(
				$registry->getCurrentValue( self::PAGE_ID, self::TABLE, $field ),
				$value,
				$field
			);
		}
	}

	public function testGetValuesForPageTableIsEmptyForUnknownTargets(): void {
		$registry = $this->allowAll();
		$this->assertSame( [], $registry->getValuesForPageTable( self::PAGE_ID, 'NoSuchTable' ) );
		$this->assertSame( [], $registry->getValuesForPageTable( 999999999, self::TABLE ) );
	}

	public function testPageWithoutACargoRowYieldsNothing(): void {
		$registry = $this->allowAll();
		$absent = self::PAGE_ID + 1;
		$this->assertSame( [], $registry->getTablesForPage( $absent ) );
		$this->assertSame( [], $registry->getSuggestableFields( $absent ) );
		$this->assertNull( $registry->getCurrentValue( $absent, self::TABLE, 'Name' ) );
	}

	public function testMaxFieldsIsHonoured(): void {
		$registry = $this->registry(
			[ self::TABLE => '*' ],
			[ 'SaintapediaSuggestMaxFields' => 2 ]
		);
		$this->assertCount( 2, $registry->getSuggestableFields( self::PAGE_ID ) );
	}

	public function testMaxFieldsOfZeroExposesNothing(): void {
		$registry = $this->registry(
			[ self::TABLE => '*' ],
			[ 'SaintapediaSuggestMaxFields' => 0 ]
		);
		$this->assertSame( [], $registry->getSuggestableFields( self::PAGE_ID ) );
	}

	public function testValuesAreClampedToTheConfiguredLength(): void {
		$registry = $this->registry(
			[ self::TABLE => '*' ],
			[ 'SaintapediaSuggestMaxValueLength' => 5 ]
		);
		foreach ( $registry->getSuggestableFields( self::PAGE_ID ) as $entry ) {
			$this->assertLessThanOrEqual( 5, mb_strlen( $entry['value'] ) );
		}
		$this->assertSame(
			'St. F',
			$registry->getCurrentValue( self::PAGE_ID, self::TABLE, 'Name' )
		);
	}
}
