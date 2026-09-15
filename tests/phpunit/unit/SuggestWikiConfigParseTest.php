<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry;
use MediaWiki\Extension\SaintapediaSuggest\SuggestWikiConfig;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure resolution rules for on-wiki operational settings.
 *
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestWikiConfig
 */
class SuggestWikiConfigParseTest extends TestCase {

	public function testParseBoolTokenAcceptsCommonSpellings(): void {
		foreach ( [ 'true', 'TRUE', 'yes', 'on', '1', ' true ' ] as $token ) {
			$this->assertTrue( SuggestWikiConfig::parseBoolToken( $token ), $token );
		}
		foreach ( [ 'false', 'FALSE', 'no', 'off', '0' ] as $token ) {
			$this->assertFalse( SuggestWikiConfig::parseBoolToken( $token ), $token );
		}
		$this->assertNull( SuggestWikiConfig::parseBoolToken( 'maybe' ) );
		$this->assertNull( SuggestWikiConfig::parseBoolToken( '' ) );
	}

	public function testResolveBoolPrefersPageValue(): void {
		$this->assertTrue( SuggestWikiConfig::resolveBool( 'true', false ) );
		$this->assertFalse( SuggestWikiConfig::resolveBool( 'false', true ) );
	}

	public function testResolveBoolKeepsPhpValueWhenPageEmptyOrJunk(): void {
		$this->assertTrue( SuggestWikiConfig::resolveBool( '', true ) );
		$this->assertFalse( SuggestWikiConfig::resolveBool( '', false ) );
		$this->assertTrue( SuggestWikiConfig::resolveBool( 'perhaps', true ) );
		$this->assertTrue( SuggestWikiConfig::resolveBool( "# just a comment\n", true ) );
	}

	/**
	 * A caller protecting a security-relevant flag can pass onReadError =
	 * true so a cache/DB blip fails closed instead of silently falling back
	 * to the PHP value. (No current caller does — require-captcha was the
	 * one that did, and it's LocalSettings.php-only since it no longer has
	 * a wiki-page override at all — but the resolver still supports it.)
	 */
	public function testResolveBoolFailsClosedOnReadErrorWhenAsked(): void {
		$this->assertTrue(
			SuggestWikiConfig::resolveBool( '', false, true, true ),
			'A read failure with onReadError=true must return true regardless of the PHP value'
		);
	}

	public function testResolveBoolKeepsPhpValueOnReadErrorByDefault(): void {
		$this->assertFalse( SuggestWikiConfig::resolveBool( '', false, true, null ) );
		$this->assertTrue( SuggestWikiConfig::resolveBool( '', true, true, null ) );
	}

	public function testResolveIntAcceptsZeroAsRealOverride(): void {
		$this->assertSame(
			0,
			SuggestWikiConfig::resolveInt( '0', 5 ),
			'0 must mean "reject every submit", not "fall back to PHP"'
		);
		$this->assertSame( 25, SuggestWikiConfig::resolveInt( '25', 5 ) );
	}

	public function testResolveIntRejectsNonNumericAndNegative(): void {
		$this->assertSame( 5, SuggestWikiConfig::resolveInt( 'lots', 5 ) );
		$this->assertSame( 5, SuggestWikiConfig::resolveInt( '-3', 5 ) );
		$this->assertSame( 5, SuggestWikiConfig::resolveInt( '', 5 ) );
	}

	public function testResolveIntKeepsPhpValueOnReadError(): void {
		$this->assertSame( 5, SuggestWikiConfig::resolveInt( '99', 5, true ) );
	}

	public function testParseLinesSharesAccessPageConventions(): void {
		$text = "# notify these people\n* Alice\nBob  # on leave\n\n; skip\nAlice\n";
		$this->assertSame( [ 'Alice', 'Bob' ], SuggestWikiConfig::parseLines( $text ) );
	}

	public function testRegisteredPagesCoverEveryOverridableSetting(): void {
		$pages = SuggestWikiConfig::pages();
		$this->assertArrayHasKey( 'SaintapediaSuggestNotifyUsersPage', $pages );
		$this->assertArrayHasKey( 'SaintapediaSuggestEnabledPage', $pages );
		$this->assertArrayHasKey( 'SaintapediaSuggestTablesPage', $pages );
	}

	/**
	 * Rate limit and require-captcha are abuse controls, pulled back to
	 * LocalSettings.php-only (matching SaintapediaFeedback 1.9.0's identical
	 * call for its own settings) — they must never come back as registered
	 * wiki-page overrides.
	 */
	public function testAbuseControlsAreNotRegisteredPages(): void {
		$pages = SuggestWikiConfig::pages();
		$this->assertArrayNotHasKey( 'SaintapediaSuggestRateLimitPage', $pages );
		$this->assertArrayNotHasKey( 'SaintapediaSuggestRequireCaptchaPage', $pages );
	}

	public function testParseTableLinesReadsFieldsAndWildcards(): void {
		// Raw, pre-normalize shape: a bare table name collapses to `true`
		// here, but "Table: *" does not — normalizeAllowList() is what
		// recognizes a literal '*' among the fields and widens it to `true`,
		// matching how the PHP-config $wgSaintapediaSuggestTables form
		// already behaves. Both sources get identical treatment past this
		// point.
		$this->assertSame(
			[
				'Footprints' => [ 'LocationTitle', 'Address', 'City' ],
				'Parishes'   => [ '*' ],
				'Dioceses'   => true,
			],
			SuggestWikiConfig::parseTableLines( [
				'Footprints: LocationTitle, Address, City',
				'Parishes: *',
				'Dioceses',
			] )
		);
	}

	public function testParseTableLinesFeedsNormalizeAllowListCleanly(): void {
		$parsed = SuggestWikiConfig::parseTableLines( [
			'Footprints: LocationTitle, Address, _ID',
			'Parishes: *',
		] );
		$this->assertSame(
			[
				'Footprints' => [ 'LocationTitle', 'Address' ],
				'Parishes'   => true,
			],
			CargoFieldRegistry::normalizeAllowList( $parsed )
		);
	}

	public function testParseTableLinesSkipsALineWithNoTableName(): void {
		$this->assertSame(
			[ 'Footprints' => true ],
			SuggestWikiConfig::parseTableLines( [ ': Address, City', 'Footprints' ] )
		);
	}

	public function testParseTableLinesTrimsFieldWhitespace(): void {
		$this->assertSame(
			[ 'Footprints' => [ 'LocationTitle', 'Address' ] ],
			SuggestWikiConfig::parseTableLines( [ 'Footprints:  LocationTitle ,  Address  ' ] )
		);
	}

	public function testEffectiveTablesRawFallsBackToPhpValue(): void {
		// No MediaWiki services (the standalone unit bootstrap) and a
		// missing/empty on-wiki page (this suite's run through MediaWiki's
		// own phpunit.php, where MediaWiki:SaintapediaSuggest-tables does
		// not exist) both take different code paths to the same outcome:
		// degrade to the PHP value rather than fatal or return nothing.
		$phpValue = [ 'Footprints' => [ 'Address' ] ];
		$this->assertSame( $phpValue, SuggestWikiConfig::effectiveTablesRaw( $phpValue ) );
	}

	public function testOverlayReadFailureMessageSaysWhatItDid(): void {
		// Arbitrary config-key strings: this formatter doesn't care whether
		// the key names an actually-registered page.
		$this->assertStringContainsString(
			'failing closed',
			SuggestWikiConfig::overlayReadFailureMessage( 'SaintapediaSuggestEnabledPage', true )
		);
		$this->assertStringContainsString(
			'using PHP value',
			SuggestWikiConfig::overlayReadFailureMessage( 'SaintapediaSuggestTablesPage', false )
		);
	}
}
