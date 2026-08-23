<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure allow-list parsing. The schema-aware and database-backed
 * parts of CargoFieldRegistry need a real Cargo install and belong in an
 * integration test.
 *
 * @covers \MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry
 */
class CargoFieldRegistryTest extends TestCase {

	public function testEmptyConfigAllowsNothing(): void {
		$this->assertSame( [], CargoFieldRegistry::normalizeAllowList( [] ) );
		$this->assertSame( [], CargoFieldRegistry::normalizeAllowList( null ) );
		$this->assertSame( [], CargoFieldRegistry::normalizeAllowList( 'Parishes' ) );
	}

	public function testExplicitFieldList(): void {
		$this->assertSame(
			[ 'Parishes' => [ 'Address', 'Phone' ] ],
			CargoFieldRegistry::normalizeAllowList( [ 'Parishes' => [ 'Address', 'Phone' ] ] )
		);
	}

	public function testStarWidensToWholeTable(): void {
		$this->assertSame(
			[ 'Parishes' => true ],
			CargoFieldRegistry::normalizeAllowList( [ 'Parishes' => '*' ] )
		);
		$this->assertSame(
			[ 'Parishes' => true ],
			CargoFieldRegistry::normalizeAllowList( [ 'Parishes' => true ] )
		);
	}

	public function testStarInsideFieldListWidensWholeTable(): void {
		$this->assertSame(
			[ 'Parishes' => true ],
			CargoFieldRegistry::normalizeAllowList( [ 'Parishes' => [ 'Address', '*' ] ] )
		);
	}

	public function testListFormMeansAllFields(): void {
		$this->assertSame(
			[ 'Parishes' => true, 'Dioceses' => true ],
			CargoFieldRegistry::normalizeAllowList( [ 'Parishes', 'Dioceses' ] )
		);
	}

	public function testSingleStringFieldIsWrapped(): void {
		$this->assertSame(
			[ 'Parishes' => [ 'Phone' ] ],
			CargoFieldRegistry::normalizeAllowList( [ 'Parishes' => 'Phone' ] )
		);
	}

	public function testWhitespaceIsTrimmed(): void {
		$this->assertSame(
			[ 'Parishes' => [ 'Phone' ] ],
			CargoFieldRegistry::normalizeAllowList( [ '  Parishes  ' => [ '  Phone  ' ] ] )
		);
	}

	public function testDuplicateFieldsCollapse(): void {
		$this->assertSame(
			[ 'Parishes' => [ 'Phone' ] ],
			CargoFieldRegistry::normalizeAllowList( [ 'Parishes' => [ 'Phone', 'Phone' ] ] )
		);
	}

	/**
	 * The whole point of the allow-list is not dumping Cargo's internal
	 * columns, so an admin cannot opt into them even explicitly.
	 */
	public function testReservedFieldsAreStrippedFromExplicitLists(): void {
		$this->assertSame(
			[ 'Parishes' => [ 'Phone' ] ],
			CargoFieldRegistry::normalizeAllowList( [
				'Parishes' => [ '_pageID', '_pageName', 'Phone' ],
			] )
		);
	}

	public function testTableWithOnlyReservedFieldsIsDropped(): void {
		$this->assertSame(
			[],
			CargoFieldRegistry::normalizeAllowList( [ 'Parishes' => [ '_pageID' ] ] )
		);
	}

	public function testEmptyTableNameIsDropped(): void {
		$this->assertSame( [], CargoFieldRegistry::normalizeAllowList( [ '  ' => [ 'Phone' ] ] ) );
	}

	/**
	 * Cargo stores a list field and a Coordinates field as `Field__full`,
	 * with no plain `Field` column on the main table. Selecting the bare
	 * name raises "Unknown column" and takes the whole table's suggestions
	 * down with it, so the mapping has to be exact.
	 *
	 * @dataProvider providePhysicalColumn
	 */
	public function testPhysicalColumn( bool $isList, string $type, string $expected ): void {
		$desc = new class( $isList, $type ) {
			public bool $mIsList;
			public string $mType;

			public function __construct( bool $isList, string $type ) {
				$this->mIsList = $isList;
				$this->mType = $type;
			}
		};
		$this->assertSame( $expected, CargoFieldRegistry::physicalColumn( 'Field', $desc ) );
	}

	public static function providePhysicalColumn(): array {
		return [
			'plain string'        => [ false, 'String', 'Field' ],
			'plain text'          => [ false, 'Text', 'Field' ],
			'page'                => [ false, 'Page', 'Field' ],
			// Date gets an extra Field__precision column, but Field itself exists.
			'date keeps own name' => [ false, 'Date', 'Field' ],
			'list of strings'     => [ true, 'String', 'Field__full' ],
			'list of pages'       => [ true, 'Page', 'Field__full' ],
			'coordinates'         => [ false, 'Coordinates', 'Field__full' ],
			'list of coordinates' => [ true, 'Coordinates', 'Field__full' ],
		];
	}

	public function testPhysicalColumnFallsBackWhenDescriptionMissing(): void {
		$this->assertSame(
			'Field',
			CargoFieldRegistry::physicalColumn( 'Field', null ),
			'An unknown description must assume the ordinary single-column layout'
		);
	}

	/* ------------------------------------------------------ row labelling */

	/**
	 * A page can store several rows in one Cargo table, and "Row 2" tells a
	 * reader nothing about which sighting or Mass time they are correcting.
	 */
	public function testRowLabelPrefersTheAdminsNominatedField(): void {
		$values = [ 'Name' => 'St. Fixture', 'LocationTitle' => 'National Shrine' ];
		$this->assertSame(
			'National Shrine',
			CargoFieldRegistry::rowLabel( $values, [ 'Name', 'LocationTitle' ], 'LocationTitle', 1 )
		);
	}

	public function testRowLabelFallsBackToFirstNonEmptyAllowListedField(): void {
		$values = [ 'Name' => '', 'LocationTitle' => 'Seton family home' ];
		$this->assertSame(
			'Seton family home',
			CargoFieldRegistry::rowLabel( $values, [ 'Name', 'LocationTitle' ], null, 2 )
		);
	}

	public function testRowLabelSkipsAnEmptyNominatedField(): void {
		$values = [ 'Name' => 'St. Fixture', 'LocationTitle' => '   ' ];
		$this->assertSame(
			'St. Fixture',
			CargoFieldRegistry::rowLabel( $values, [ 'Name', 'LocationTitle' ], 'LocationTitle', 1 )
		);
	}

	public function testRowLabelFallsBackToTheOrdinal(): void {
		$this->assertSame(
			'Row 3',
			CargoFieldRegistry::rowLabel( [ 'Name' => '', 'City' => '' ], [ 'Name', 'City' ], null, 3 )
		);
	}

	/**
	 * The ordinal fallback is localised by the caller, because this class is
	 * deliberately free of MediaWiki's message system.
	 */
	public function testRowLabelUsesTheInjectedOrdinalFormatter(): void {
		$this->assertSame(
			'Ligne 2',
			CargoFieldRegistry::rowLabel( [], [ 'Name' ], null, 2, static function ( $n ) {
				return 'Ligne ' . $n;
			} )
		);
	}

	public function testRowLabelIsTruncated(): void {
		$long = str_repeat( 'a', CargoFieldRegistry::MAX_ROW_LABEL_LENGTH + 40 );
		$label = CargoFieldRegistry::rowLabel( [ 'Name' => $long ], [ 'Name' ], null, 1 );
		$this->assertSame( CargoFieldRegistry::MAX_ROW_LABEL_LENGTH, mb_strlen( $label ) );
	}

	public function testRowLabelTrimsWhitespace(): void {
		$this->assertSame(
			'St. Fixture',
			CargoFieldRegistry::rowLabel( [ 'Name' => "  St. Fixture\n" ], [ 'Name' ], null, 1 )
		);
	}

	public function testRowLabelIgnoresFieldsOutsideTheAllowList(): void {
		// A value the reader was never offered must not become the row's name.
		$values = [ 'Secret' => 'internal note', 'Name' => '' ];
		$this->assertSame(
			'Row 1',
			CargoFieldRegistry::rowLabel( $values, [ 'Name' ], null, 1 )
		);
	}

	public function testIsReservedField(): void {
		$this->assertTrue( CargoFieldRegistry::isReservedField( '_pageID' ) );
		$this->assertTrue( CargoFieldRegistry::isReservedField( '_pageName' ) );
		$this->assertTrue( CargoFieldRegistry::isReservedField( '' ) );
		$this->assertFalse( CargoFieldRegistry::isReservedField( 'Phone' ) );
		$this->assertFalse( CargoFieldRegistry::isReservedField( 'Address_2' ) );
	}
}
