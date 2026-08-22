<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Integration;

use HashConfig;
use MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiIntegrationTestCase;

/**
 * CargoFieldRegistry against a live Cargo install.
 *
 * Skipped when Cargo is absent, and skipped per-test when the wiki has no
 * Cargo table to exercise — these assertions are about how this extension
 * talks to Cargo, not about any particular wiki's content.
 *
 * @group Database
 * @group SaintapediaSuggest
 * @covers \MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry
 */
class CargoFieldRegistryIntegrationTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Cargo' ) ) {
			$this->markTestSkipped( 'Cargo is not installed on this wiki.' );
		}
	}

	/**
	 * @param array<string,mixed> $tables
	 */
	private function registry( array $tables ): CargoFieldRegistry {
		return new CargoFieldRegistry(
			new HashConfig( [
				'SaintapediaSuggestTables' => $tables,
				'SaintapediaSuggestMaxFields' => 40,
				'SaintapediaSuggestMaxValueLength' => 500,
			] ),
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
	}

	/**
	 * First Cargo table on this wiki that actually holds a row, with the page
	 * id that owns it. Returns null when the wiki has no Cargo data.
	 *
	 * @return array{0:string,1:int}|null
	 */
	private function findPopulatedTable(): ?array {
		$dbr = $this->getDb();
		if ( !$dbr->tableExists( 'cargo_pages', __METHOD__ ) ) {
			return null;
		}
		$row = $dbr->selectRow(
			'cargo_pages',
			[ 'page_id', 'table_name' ],
			[],
			__METHOD__,
			[ 'ORDER BY' => 'page_id ASC' ]
		);
		if ( !$row ) {
			return null;
		}
		return [ (string)$row->table_name, (int)$row->page_id ];
	}

	/**
	 * @return array{0:CargoFieldRegistry,1:string,2:int}
	 */
	private function populatedRegistry(): array {
		$found = $this->findPopulatedTable();
		if ( !$found ) {
			$this->markTestSkipped( 'This wiki has no Cargo data to exercise.' );
		}
		[ $table, $pageId ] = $found;
		return [ $this->registry( [ $table => '*' ] ), $table, $pageId ];
	}

	public function testCargoIsDetected(): void {
		$this->assertTrue( $this->registry( [] )->isCargoAvailable() );
	}

	public function testEmptyAllowListExposesNothing(): void {
		$found = $this->findPopulatedTable();
		if ( !$found ) {
			$this->markTestSkipped( 'This wiki has no Cargo data to exercise.' );
		}
		[ , $pageId ] = $found;

		$registry = $this->registry( [] );
		$this->assertSame( [], $registry->getTablesForPage( $pageId ) );
		$this->assertSame( [], $registry->getSuggestableFields( $pageId ) );
	}

	public function testAllowListedTableIsFoundForItsPage(): void {
		[ $registry, $table, $pageId ] = $this->populatedRegistry();
		$this->assertContains( $table, $registry->getTablesForPage( $pageId ) );
	}

	/**
	 * The point of the allow-list: an admin cannot expose Cargo's internal
	 * bookkeeping columns, not even with the '*' wildcard.
	 */
	public function testReservedColumnsAreNeverExposed(): void {
		[ $registry, $table, $pageId ] = $this->populatedRegistry();

		foreach ( $registry->getAllowedFields( $table ) as $field ) {
			$this->assertStringStartsNotWith( '_', $field );
		}
		foreach ( $registry->getSuggestableFields( $pageId ) as $entry ) {
			$this->assertStringStartsNotWith( '_', $entry['field'] );
		}
		$this->assertFalse( $registry->isAllowed( $table, '_pageID' ) );
		$this->assertFalse( $registry->isAllowed( $table, '_pageName' ) );
		$this->assertNull( $registry->getCurrentValue( $pageId, $table, '_pageID' ) );
	}

	public function testUnknownTableAndFieldAreRefused(): void {
		[ $registry, $table, $pageId ] = $this->populatedRegistry();

		$this->assertFalse( $registry->isAllowed( 'NoSuchCargoTable', 'Whatever' ) );
		$this->assertFalse( $registry->isAllowed( $table, 'NoSuchFieldAtAll' ) );
		$this->assertNull( $registry->getCurrentValue( $pageId, $table, 'NoSuchFieldAtAll' ) );
	}

	/**
	 * Every field the registry offers must be readable. This is the
	 * regression guard for Cargo's `__full` columns: list and Coordinates
	 * fields have no plain column, and selecting one by its bare schema name
	 * raises "Unknown column" and takes the whole table down with it.
	 */
	public function testEverySuggestableFieldIsActuallyReadable(): void {
		[ $registry, $table, $pageId ] = $this->populatedRegistry();

		$fields = $registry->getSuggestableFields( $pageId );
		if ( !$fields ) {
			$this->markTestSkipped( "No suggestable fields on $table for page $pageId." );
		}

		foreach ( $fields as $entry ) {
			$this->assertIsString( $entry['value'], "{$entry['field']} returned a non-string" );
			$this->assertSame(
				$entry['value'],
				$registry->getCurrentValue( $pageId, $table, $entry['field'] ),
				"getCurrentValue disagrees with getSuggestableFields for {$entry['field']}"
			);
		}
	}

	public function testPhysicalColumnMatchesCargosLayout(): void {
		[ $registry, $table, $pageId ] = $this->populatedRegistry();

		foreach ( $registry->getSuggestableFields( $pageId ) as $entry ) {
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

	public function testPageWithoutACargoRowYieldsNothing(): void {
		[ $registry, $table ] = $this->populatedRegistry();
		$absent = 999999999;
		$this->assertSame( [], $registry->getTablesForPage( $absent ) );
		$this->assertSame( [], $registry->getSuggestableFields( $absent ) );
		$this->assertNull( $registry->getCurrentValue( $absent, $table, 'Anything' ) );
	}

	public function testMaxFieldsIsHonoured(): void {
		$found = $this->findPopulatedTable();
		if ( !$found ) {
			$this->markTestSkipped( 'This wiki has no Cargo data to exercise.' );
		}
		[ $table, $pageId ] = $found;

		$registry = new CargoFieldRegistry(
			new HashConfig( [
				'SaintapediaSuggestTables' => [ $table => '*' ],
				'SaintapediaSuggestMaxFields' => 1,
				'SaintapediaSuggestMaxValueLength' => 500,
			] ),
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
		$this->assertLessThanOrEqual( 1, count( $registry->getSuggestableFields( $pageId ) ) );
	}

	public function testValuesAreClampedToTheConfiguredLength(): void {
		$found = $this->findPopulatedTable();
		if ( !$found ) {
			$this->markTestSkipped( 'This wiki has no Cargo data to exercise.' );
		}
		[ $table, $pageId ] = $found;

		$registry = new CargoFieldRegistry(
			new HashConfig( [
				'SaintapediaSuggestTables' => [ $table => '*' ],
				'SaintapediaSuggestMaxFields' => 40,
				'SaintapediaSuggestMaxValueLength' => 5,
			] ),
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
		foreach ( $registry->getSuggestableFields( $pageId ) as $entry ) {
			$this->assertLessThanOrEqual( 5, mb_strlen( $entry['value'] ) );
		}
	}
}
