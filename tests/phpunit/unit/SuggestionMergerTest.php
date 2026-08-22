<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\SuggestionMerger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestionMerger
 */
class SuggestionMergerTest extends TestCase {

	/** @dataProvider provideEquivalentValues */
	public function testEquivalentValuesMatch( string $a, string $b, string $why ): void {
		$this->assertTrue( SuggestionMerger::valuesMatch( $a, $b ), $why );
	}

	public static function provideEquivalentValues(): array {
		return [
			'identical'        => [ 'Sacred Heart', 'Sacred Heart', 'identical strings' ],
			'leading trailing' => [ '  Sacred Heart ', 'Sacred Heart', 'surrounding whitespace' ],
			'inner whitespace' => [ "Sacred   Heart", 'Sacred Heart', 'collapsed run of spaces' ],
			'newline pasted'   => [ "Sacred\nHeart", 'Sacred Heart', 'newline from a paste' ],
			'tab pasted'       => [ "Sacred\tHeart", 'Sacred Heart', 'tab from a paste' ],
			'case'             => [ 'SACRED HEART', 'sacred heart', 'case folded' ],
			'accented case'    => [ 'MÜNCHEN', 'münchen', 'Unicode-aware lowercasing' ],
			'curly apostrophe' => [ "St. Mary\u{2019}s", "St. Mary's", 'phone vs laptop apostrophe' ],
			'curly quotes'     => [ "\u{201C}Hi\u{201D}", '"Hi"', 'smart double quotes' ],
			'en dash'          => [ "555\u{2013}0100", '555-0100', 'en dash typed as a hyphen' ],
			'em dash'          => [ "555\u{2014}0100", '555-0100', 'em dash typed as a hyphen' ],
			'minus sign'       => [ "555\u{2212}0100", '555-0100', 'Unicode minus' ],
			'nbsp'             => [ "Sacred\u{00A0}Heart", 'Sacred Heart', 'non-breaking space' ],
			'zero width'       => [ "Sacred\u{200B} Heart", 'Sacred Heart', 'zero-width space' ],
		];
	}

	/** @dataProvider provideDistinctValues */
	public function testDistinctValuesDoNotMatch( string $a, string $b, string $why ): void {
		$this->assertFalse( SuggestionMerger::valuesMatch( $a, $b ), $why );
	}

	public static function provideDistinctValues(): array {
		return [
			'different text'   => [ 'Sacred Heart', 'Holy Cross', 'unrelated values' ],
			'digit separators' => [ '555-0100', '5550100', 'punctuation is meaningful in a field value' ],
			'extra word'       => [ 'Sacred Heart', 'Sacred Heart Parish', 'one is more specific' ],
			'transposed'       => [ '555-0100', '555-0010', 'transposed digits are a real difference' ],
			'trailing period'  => [ 'Sacred Heart', 'Sacred Heart.', 'punctuation is not stripped' ],
		];
	}

	/**
	 * An empty proposal matches nothing at all, including another empty one:
	 * folding empties together would collapse unrelated reports.
	 */
	public function testEmptyNeverMatches(): void {
		$this->assertFalse( SuggestionMerger::valuesMatch( '', '' ) );
		$this->assertFalse( SuggestionMerger::valuesMatch( '   ', '' ) );
		$this->assertFalse( SuggestionMerger::valuesMatch( null, null ) );
		$this->assertFalse( SuggestionMerger::valuesMatch( 'x', null ) );
	}

	public function testNormalizeValue(): void {
		$this->assertSame( '', SuggestionMerger::normalizeValue( null ) );
		$this->assertSame( '', SuggestionMerger::normalizeValue( "  \n\t " ) );
		$this->assertSame( 'sacred heart', SuggestionMerger::normalizeValue( '  Sacred   HEART ' ) );
	}

	public function testStatusAcceptsDuplicates(): void {
		$this->assertTrue( SuggestionMerger::statusAcceptsDuplicates( 'new' ) );
		$this->assertTrue( SuggestionMerger::statusAcceptsDuplicates( 'reviewed' ) );
		$this->assertFalse( SuggestionMerger::statusAcceptsDuplicates( 'actioned' ) );
		$this->assertFalse( SuggestionMerger::statusAcceptsDuplicates( 'dismissed' ) );
		$this->assertFalse( SuggestionMerger::statusAcceptsDuplicates( null ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function candidates( array $rows ): array {
		return array_map( static function ( $r ) {
			return (object)( $r + [
				'sg_id' => 1,
				'sg_status' => 'new',
				'sg_suggested_value' => '',
				'sg_duplicate_of' => null,
			] );
		}, $rows );
	}

	public function testPickCanonicalFindsTheMatch(): void {
		$candidates = $this->candidates( [
			[ 'sg_id' => 5, 'sg_suggested_value' => 'Holy Cross' ],
			[ 'sg_id' => 9, 'sg_suggested_value' => 'Sacred Heart' ],
		] );
		$this->assertSame( 9, SuggestionMerger::pickCanonical( $candidates, '  sacred heart ' ) );
	}

	public function testPickCanonicalReturnsNullWhenNothingMatches(): void {
		$candidates = $this->candidates( [ [ 'sg_id' => 5, 'sg_suggested_value' => 'Holy Cross' ] ] );
		$this->assertNull( SuggestionMerger::pickCanonical( $candidates, 'Sacred Heart' ) );
		$this->assertNull( SuggestionMerger::pickCanonical( [], 'Sacred Heart' ) );
	}

	/**
	 * The oldest matching row wins, so the queue item keeps the timestamp of
	 * whoever reported the problem first.
	 */
	public function testOldestCanonicalWins(): void {
		$candidates = $this->candidates( [
			[ 'sg_id' => 30, 'sg_suggested_value' => 'Sacred Heart' ],
			[ 'sg_id' => 12, 'sg_suggested_value' => 'Sacred Heart' ],
			[ 'sg_id' => 44, 'sg_suggested_value' => 'Sacred Heart' ],
		] );
		$this->assertSame( 12, SuggestionMerger::pickCanonical( $candidates, 'Sacred Heart' ) );
	}

	public function testClosedCandidatesAreSkipped(): void {
		foreach ( [ 'actioned', 'dismissed' ] as $status ) {
			$candidates = $this->candidates( [
				[ 'sg_id' => 5, 'sg_status' => $status, 'sg_suggested_value' => 'Sacred Heart' ],
			] );
			$this->assertNull(
				SuggestionMerger::pickCanonical( $candidates, 'Sacred Heart' ),
				"A $status suggestion must not absorb a fresh report"
			);
		}
	}

	/**
	 * Duplicate links must stay one level deep; folding into a row that is
	 * itself a duplicate would build a chain nothing walks.
	 */
	public function testCandidatesThatAreThemselvesDuplicatesAreSkipped(): void {
		$candidates = $this->candidates( [
			[ 'sg_id' => 7, 'sg_suggested_value' => 'Sacred Heart', 'sg_duplicate_of' => 3 ],
		] );
		$this->assertNull( SuggestionMerger::pickCanonical( $candidates, 'Sacred Heart' ) );
	}

	public function testCanonicalIsPreferredOverADuplicateWithTheSameValue(): void {
		$candidates = $this->candidates( [
			[ 'sg_id' => 2, 'sg_suggested_value' => 'Sacred Heart', 'sg_duplicate_of' => 1 ],
			[ 'sg_id' => 8, 'sg_suggested_value' => 'Sacred Heart' ],
		] );
		$this->assertSame( 8, SuggestionMerger::pickCanonical( $candidates, 'Sacred Heart' ) );
	}

	public function testEmptyProposalPicksNothing(): void {
		$candidates = $this->candidates( [ [ 'sg_id' => 5, 'sg_suggested_value' => '' ] ] );
		$this->assertNull( SuggestionMerger::pickCanonical( $candidates, '  ' ) );
	}

	public function testMalformedCandidatesAreIgnored(): void {
		$candidates = array_merge(
			[ 'not an object', 42, null ],
			$this->candidates( [ [ 'sg_id' => 0, 'sg_suggested_value' => 'Sacred Heart' ] ] )
		);
		$this->assertNull( SuggestionMerger::pickCanonical( $candidates, 'Sacred Heart' ) );
	}
}
