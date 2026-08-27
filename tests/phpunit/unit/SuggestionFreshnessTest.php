<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\SuggestionFreshness;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestionFreshness
 */
class SuggestionFreshnessTest extends TestCase {

	public function testLiveValueStillMatchesTheSnapshot(): void {
		$this->assertSame(
			SuggestionFreshness::CURRENT,
			SuggestionFreshness::classify( 'Birmingham, AL', 'Birmingham, AL', 'Bessemer, AL' )
		);
	}

	public function testLiveValueAlreadyEqualsTheProposal(): void {
		$this->assertSame(
			SuggestionFreshness::APPLIED,
			SuggestionFreshness::classify( 'Birmingham, AL', 'Bessemer, AL', 'Bessemer, AL' )
		);
	}

	public function testLiveValueIsNeither(): void {
		$this->assertSame(
			SuggestionFreshness::CHANGED,
			SuggestionFreshness::classify( 'Birmingham, AL', 'Hoover, AL', 'Bessemer, AL' )
		);
	}

	/**
	 * The row or field is gone from Cargo — nothing can be asserted, so no
	 * badge rather than a misleading one.
	 */
	public function testMissingLiveValueIsUnknown(): void {
		$this->assertSame(
			SuggestionFreshness::UNKNOWN,
			SuggestionFreshness::classify( 'Birmingham, AL', null, 'Bessemer, AL' )
		);
	}

	/**
	 * Comparison reuses the duplicate-matching normalization, so a
	 * typographic difference does not flip "already applied" to "changed".
	 */
	public function testComparisonToleratesCosmeticDifferences(): void {
		$this->assertSame(
			SuggestionFreshness::APPLIED,
			SuggestionFreshness::classify( 'old', '  BESSEMER,   al ', 'Bessemer, AL' )
		);
		$this->assertSame(
			SuggestionFreshness::CURRENT,
			SuggestionFreshness::classify( "St. Mary's", "St. Mary\u{2019}s", 'Something else' )
		);
	}

	public function testEmptyLiveValueIsNotTreatedAsApplied(): void {
		// An empty proposal never matches, so an empty field reads as either
		// unchanged-from-empty or changed, never "already applied".
		$this->assertSame(
			SuggestionFreshness::CURRENT,
			SuggestionFreshness::classify( '', '', 'Something' )
		);
		$this->assertSame(
			SuggestionFreshness::CHANGED,
			SuggestionFreshness::classify( 'was here', '', 'Something' )
		);
	}

	public function testNullSnapshotComparesAgainstEmpty(): void {
		$this->assertSame(
			SuggestionFreshness::CURRENT,
			SuggestionFreshness::classify( null, '', 'Something' )
		);
	}

	public function testOnlyAppliedAndChangedAreNoteworthy(): void {
		$this->assertTrue( SuggestionFreshness::isNoteworthy( SuggestionFreshness::APPLIED ) );
		$this->assertTrue( SuggestionFreshness::isNoteworthy( SuggestionFreshness::CHANGED ) );
		$this->assertFalse( SuggestionFreshness::isNoteworthy( SuggestionFreshness::CURRENT ) );
		$this->assertFalse( SuggestionFreshness::isNoteworthy( SuggestionFreshness::UNKNOWN ) );
	}

	/**
	 * The case worth shouting about: a reviewer asserted the change was made
	 * elsewhere, and the data says otherwise.
	 */
	public function testActionedButUnchanged(): void {
		$this->assertTrue( SuggestionFreshness::isActionedButUnchanged(
			'actioned', SuggestionFreshness::CURRENT ) );

		// Not flagged for any other status …
		foreach ( [ 'new', 'reviewed', 'dismissed' ] as $status ) {
			$this->assertFalse( SuggestionFreshness::isActionedButUnchanged(
				$status, SuggestionFreshness::CURRENT ), $status );
		}
		// … nor when the data actually moved.
		foreach ( [ SuggestionFreshness::APPLIED, SuggestionFreshness::CHANGED,
					SuggestionFreshness::UNKNOWN ] as $state ) {
			$this->assertFalse( SuggestionFreshness::isActionedButUnchanged(
				'actioned', $state ), $state );
		}
	}
}
