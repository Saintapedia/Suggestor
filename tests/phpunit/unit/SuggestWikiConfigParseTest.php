<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

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
	 * A cache or database blip must not be able to switch the captcha off:
	 * the captcha caller passes onReadError = true so it fails closed.
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
		$this->assertArrayHasKey( 'SaintapediaSuggestRateLimitPage', $pages );
		$this->assertArrayHasKey( 'SaintapediaSuggestNotifyUsersPage', $pages );
		$this->assertArrayHasKey( 'SaintapediaSuggestRequireCaptchaPage', $pages );
		$this->assertArrayHasKey( 'SaintapediaSuggestEnabledPage', $pages );
	}

	public function testOverlayReadFailureMessageSaysWhatItDid(): void {
		$this->assertStringContainsString(
			'failing closed',
			SuggestWikiConfig::overlayReadFailureMessage( 'SaintapediaSuggestRequireCaptchaPage', true )
		);
		$this->assertStringContainsString(
			'using PHP value',
			SuggestWikiConfig::overlayReadFailureMessage( 'SaintapediaSuggestRateLimitPage', false )
		);
	}
}
