<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\SuggestAccess;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure on-wiki access-page parsing and the group-matching rule.
 *
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestAccess
 */
class SuggestAccessParseTest extends TestCase {

	public function testParsesOneGroupPerLine(): void {
		$this->assertSame(
			[ 'sysop', 'editor' ],
			SuggestAccess::parseGroupList( "sysop\neditor" )
		);
	}

	public function testIgnoresCommentsAndBlankLines(): void {
		$text = "# administrators\n\nsysop\n; another comment\neditor  # trailing\n";
		$this->assertSame( [ 'sysop', 'editor' ], SuggestAccess::parseGroupList( $text ) );
	}

	public function testStripsWikiListMarkers(): void {
		$this->assertSame( [ 'sysop', 'user' ], SuggestAccess::parseGroupList( "* sysop\n* user" ) );
	}

	public function testLoneStarSurvivesListMarkerStripping(): void {
		$this->assertSame( [ '*' ], SuggestAccess::parseGroupList( '*' ) );
		$this->assertSame( [ '*' ], SuggestAccess::parseGroupList( '* *' ) );
	}

	public function testDeduplicates(): void {
		$this->assertSame( [ 'sysop' ], SuggestAccess::parseGroupList( "sysop\nsysop\n* sysop" ) );
	}

	public function testHandlesCrlf(): void {
		$this->assertSame( [ 'sysop', 'editor' ], SuggestAccess::parseGroupList( "sysop\r\neditor" ) );
	}

	public function testEmptyTextYieldsNoGroups(): void {
		$this->assertSame( [], SuggestAccess::parseGroupList( '' ) );
		$this->assertSame( [], SuggestAccess::parseGroupList( "\n\n# only comments\n" ) );
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
