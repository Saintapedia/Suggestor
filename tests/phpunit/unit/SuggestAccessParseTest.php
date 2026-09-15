<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\SuggestAccess;
use PHPUnit\Framework\TestCase;

/**
 * Covers normalizeLine() (still load-bearing for SuggestWikiConfig's
 * remaining wiki-overridable settings), the group-matching rule, and the
 * pure half of the LocalSettings-only access-group resolution.
 *
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestAccess
 */
class SuggestAccessParseTest extends TestCase {

	public function testNormalizeLineIgnoresCommentsAndBlankLines(): void {
		$this->assertNull( SuggestAccess::normalizeLine( '' ) );
		$this->assertNull( SuggestAccess::normalizeLine( '   ' ) );
		$this->assertNull( SuggestAccess::normalizeLine( '# a comment' ) );
		$this->assertNull( SuggestAccess::normalizeLine( '; also a comment' ) );
		$this->assertSame( 'sysop', SuggestAccess::normalizeLine( 'sysop' ) );
		$this->assertSame( 'editor', SuggestAccess::normalizeLine( 'editor  # trailing' ) );
	}

	public function testNormalizeLineStripsWikiListMarkers(): void {
		$this->assertSame( 'sysop', SuggestAccess::normalizeLine( '* sysop' ) );
		$this->assertSame( 'user', SuggestAccess::normalizeLine( '* user' ) );
	}

	public function testNormalizeLineLoneStarSurvivesListMarkerStripping(): void {
		$this->assertSame( '*', SuggestAccess::normalizeLine( '*' ) );
		$this->assertSame( '*', SuggestAccess::normalizeLine( '* *' ) );
	}

	public function testWithoutPublicWildcardDropsStarOnly(): void {
		// A '*' input intentionally calls wfLogWarning() when MediaWiki core
		// is loaded (it isn't, in the standalone unit bootstrap this suite
		// is meant to run under) — @-suppressed here since only the return
		// value is under test, not the log call itself.
		$this->assertSame(
			[ 'sysop', 'editor' ],
			@SuggestAccess::withoutPublicWildcard( [ 'sysop', '*', 'editor' ], 'SaintapediaSuggestEmailAccessGroups' )
		);
		$this->assertSame(
			[ 'sysop' ],
			SuggestAccess::withoutPublicWildcard( [ 'sysop' ], 'SaintapediaSuggestEmailAccessGroups' )
		);
		$this->assertSame(
			[],
			@SuggestAccess::withoutPublicWildcard( [ '*' ], 'SaintapediaSuggestEmailAccessGroups' )
		);
	}

	public function testStarGrantsEveryone(): void {
		$anon = $this->makeUser( false, false );
		$this->assertTrue( SuggestAccess::groupsGrantAccess( [ '*' ], $anon, [] ) );
	}

	public function testUserTokenMatchesNamedAccountOnly(): void {
		$named = $this->makeUser( true, false );
		$temp  = $this->makeUser( true, true );
		$anon  = $this->makeUser( false, false );

		$this->assertTrue( SuggestAccess::groupsGrantAccess( [ 'user' ], $named, [ 'user' ] ) );
		$this->assertFalse(
			SuggestAccess::groupsGrantAccess( [ 'user' ], $temp, [ 'user' ] ),
			'A MediaWiki temp account must not satisfy the "user" token'
		);
		$this->assertFalse( SuggestAccess::groupsGrantAccess( [ 'user' ], $anon, [] ) );
	}

	public function testNamedGroupMatchesEffectiveGroups(): void {
		$user = $this->makeUser( true, false );
		$this->assertTrue( SuggestAccess::groupsGrantAccess( [ 'sysop' ], $user, [ 'user', 'sysop' ] ) );
		$this->assertFalse( SuggestAccess::groupsGrantAccess( [ 'sysop' ], $user, [ 'user' ] ) );
	}

	public function testEmptyGroupListFallsBackToDefault(): void {
		$user = $this->makeUser( true, false );
		$this->assertTrue(
			SuggestAccess::groupsGrantAccess( [], $user, [ 'sysop' ] ),
			'An empty list must fall back to DEFAULT_GROUPS (sysop), not allow everyone'
		);
		$this->assertFalse( SuggestAccess::groupsGrantAccess( [], $user, [ 'user' ] ) );
	}

	public function testIsPersistentAccount(): void {
		$this->assertTrue( SuggestAccess::isPersistentAccount( $this->makeUser( true, false ) ) );
		$this->assertFalse( SuggestAccess::isPersistentAccount( $this->makeUser( true, true ) ) );
		$this->assertFalse( SuggestAccess::isPersistentAccount( $this->makeUser( false, false ) ) );
		$this->assertFalse( SuggestAccess::isPersistentAccount( null ) );
	}

	/**
	 * Stand-in for a MediaWiki User, so these stay unit tests.
	 */
	private function makeUser( bool $registered, bool $temp ) {
		return new class( $registered, $temp ) {
			private bool $registered;
			private bool $temp;

			public function __construct( bool $registered, bool $temp ) {
				$this->registered = $registered;
				$this->temp = $temp;
			}

			public function isRegistered(): bool {
				return $this->registered;
			}

			public function isTemp(): bool {
				return $this->temp;
			}
		};
	}
}
