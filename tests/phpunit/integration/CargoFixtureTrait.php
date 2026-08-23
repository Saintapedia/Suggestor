<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Integration;

use CargoUtils;

/**
 * Builds a small Cargo table with a row, for integration tests.
 *
 * Tests build their own Cargo data rather than reading whatever the wiki
 * happens to contain: MediaWiki's test framework clones tables into a
 * prefixed test database, so pre-existing Cargo content is invisible and a
 * content-dependent suite silently skips everything.
 *
 * The fixture covers each way Cargo lays a field out, because they are not
 * the same and the difference has already caused one real bug:
 *
 *   Name      String         -> column `Name`
 *   City      Page           -> column `City`
 *   Founded   Date           -> columns `Founded` + `Founded__precision`
 *   Aliases   List of String -> column `Aliases__full` (no `Aliases`)
 *   Location  Coordinates    -> `Location__full`, `__lat`, `__lon` (no `Location`)
 */
trait CargoFixtureTrait {

	private const FIXTURE_TABLE = 'SuggestFixtureTable';

	private const FIXTURE_PAGE_ID = 4242;

	/** Table whose fixture page deliberately holds several rows. */
	private const FIXTURE_MULTI_TABLE = 'SuggestMultiRowTable';

	/** Set once the physical table exists, so teardown knows to drop it. */
	private bool $cargoFixtureCreated = false;

	/** Same, for the multi-row table. */
	private bool $cargoMultiFixtureCreated = false;

	/**
	 * Cargo's serialized schema, in the shape
	 * CargoFieldDescription::newFromDBArray() consumes.
	 */
	private function cargoFixtureSchema(): string {
		return serialize( [
			'Name'     => [ 'type' => 'String' ],
			'City'     => [ 'type' => 'Page' ],
			'Founded'  => [ 'type' => 'Date' ],
			'Aliases'  => [ 'type' => 'String', 'isList' => true, 'delimiter' => ',' ],
			'Location' => [ 'type' => 'Coordinates' ],
		] );
	}

	/**
	 * @param int|null $pageId Page the row belongs to (defaults to FIXTURE_PAGE_ID)
	 */
	private function createCargoFixture( ?int $pageId = null ): void {
		$pageId ??= self::FIXTURE_PAGE_ID;

		$cdb = CargoUtils::getDB();
		$physical = $cdb->tableName( self::FIXTURE_TABLE );

		$cdb->query( 'DROP TABLE IF EXISTS ' . $physical, __METHOD__ );
		$cdb->query(
			"CREATE TABLE {$physical} (
				_ID INT NOT NULL,
				_pageID INT NOT NULL,
				_pageName VARCHAR(255) NOT NULL,
				_pageTitle VARCHAR(255) NOT NULL,
				_pageNamespace INT NOT NULL,
				Name VARCHAR(300) NULL,
				City VARCHAR(300) NULL,
				Founded DATE NULL,
				Founded__precision INT NULL,
				Aliases__full TEXT NULL,
				Location__full TEXT NULL,
				Location__lat FLOAT NULL,
				Location__lon FLOAT NULL
			)",
			__METHOD__
		);
		$this->cargoFixtureCreated = true;

		$cdb->insert( self::FIXTURE_TABLE, [
			'_ID'                => 1,
			'_pageID'            => $pageId,
			'_pageName'          => 'Suggest Fixture Page',
			'_pageTitle'         => 'Suggest Fixture Page',
			'_pageNamespace'     => NS_MAIN,
			'Name'               => 'St. Fixture',
			'City'               => 'Birmingham, AL',
			'Founded'            => '1908-01-01',
			'Founded__precision' => 1,
			'Aliases__full'      => 'St Fixture,Saint Fixture',
			'Location__full'     => '33.56557, -86.72564',
			'Location__lat'      => 33.56557,
			'Location__lon'      => -86.72564,
		], __METHOD__ );

		// cargo_tables / cargo_pages live in the wiki database, so these rows
		// go through the cloned test connection and roll back with it.
		$dbw = $this->getDb();
		$dbw->insert( 'cargo_tables', [
			'template_id'         => 0,
			'main_table'          => self::FIXTURE_TABLE,
			'field_tables'        => serialize( [ 'Aliases' => self::FIXTURE_TABLE . '__Aliases' ] ),
			'field_helper_tables' => serialize( [] ),
			'table_schema'        => $this->cargoFixtureSchema(),
		], __METHOD__ );

		$dbw->insert( 'cargo_pages', [
			'page_id'    => $pageId,
			'table_name' => self::FIXTURE_TABLE,
		], __METHOD__ );
	}

	/**
	 * A second table where the same page stores THREE rows.
	 *
	 * This is the shape that made row identity necessary: a saint's page with
	 * several sightings, or a parish with several Mass times. Without a row
	 * id, every suggestion against such a table silently referred to whichever
	 * row the database returned first.
	 *
	 * @param int|null $pageId Page the rows belong to
	 */
	private function createMultiRowCargoFixture( ?int $pageId = null ): void {
		$pageId ??= self::FIXTURE_PAGE_ID;

		$cdb = CargoUtils::getDB();
		$physical = $cdb->tableName( self::FIXTURE_MULTI_TABLE );

		$cdb->query( 'DROP TABLE IF EXISTS ' . $physical, __METHOD__ );
		$cdb->query(
			"CREATE TABLE {$physical} (
				_ID INT NOT NULL,
				_pageID INT NOT NULL,
				_pageName VARCHAR(255) NOT NULL,
				_pageTitle VARCHAR(255) NOT NULL,
				_pageNamespace INT NOT NULL,
				LocationTitle VARCHAR(300) NULL,
				SiteType VARCHAR(300) NULL,
				EventYear VARCHAR(300) NULL
			)",
			__METHOD__
		);
		$this->cargoMultiFixtureCreated = true;

		$rows = [
			[ 1, 'National Shrine of Saint Elizabeth Ann Seton', 'Shrine', '1809' ],
			[ 2, "St. Peter's Church (Barclay Street)", 'Church', '1805' ],
			[ 3, 'Seton family home site (State Street area)', 'Home', '1794' ],
		];
		foreach ( $rows as [ $id, $title, $type, $year ] ) {
			$cdb->insert( self::FIXTURE_MULTI_TABLE, [
				'_ID'            => $id,
				'_pageID'        => $pageId,
				'_pageName'      => 'Suggest Fixture Page',
				'_pageTitle'     => 'Suggest Fixture Page',
				'_pageNamespace' => NS_MAIN,
				'LocationTitle'  => $title,
				'SiteType'       => $type,
				'EventYear'      => $year,
			], __METHOD__ );
		}

		$dbw = $this->getDb();
		$dbw->insert( 'cargo_tables', [
			'template_id'         => 1,
			'main_table'          => self::FIXTURE_MULTI_TABLE,
			'field_tables'        => serialize( [] ),
			'field_helper_tables' => serialize( [] ),
			'table_schema'        => serialize( [
				'LocationTitle' => [ 'type' => 'String' ],
				'SiteType'      => [ 'type' => 'String' ],
				'EventYear'     => [ 'type' => 'String' ],
			] ),
		], __METHOD__ );

		$dbw->insert( 'cargo_pages', [
			'page_id'    => $pageId,
			'table_name' => self::FIXTURE_MULTI_TABLE,
		], __METHOD__ );
	}

	/**
	 * The Cargo data tables are reached through CargoUtils::getDB(), which uses
	 * Cargo's own prefix and is therefore NOT part of the cloned test
	 * database. They have to be dropped explicitly.
	 */
	private function dropCargoFixture(): void {
		$tables = [];
		if ( $this->cargoFixtureCreated ) {
			$tables[] = self::FIXTURE_TABLE;
		}
		if ( $this->cargoMultiFixtureCreated ) {
			$tables[] = self::FIXTURE_MULTI_TABLE;
		}
		foreach ( $tables as $table ) {
			try {
				$cdb = CargoUtils::getDB();
				$cdb->query( 'DROP TABLE IF EXISTS ' . $cdb->tableName( $table ), __METHOD__ );
			} catch ( \Throwable $e ) {
				// Best effort; a leftover fixture table is harmless.
			}
		}
		$this->cargoFixtureCreated = false;
		$this->cargoMultiFixtureCreated = false;
	}
}
