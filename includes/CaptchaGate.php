<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use Config;
use ExtensionRegistry;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Hooks as ConfirmEditHooks;
use MediaWiki\MediaWikiServices;
use OutputPage;
use User;
use WebRequest;

/**
 * Captcha policy for open (mostly anonymous) suggestion submit.
 *
 * Public mode: captcha required by default (almost all submitters are anon).
 * Enterprise mode: captcha off by default (more logged-in staff).
 * Per-wiki override via $wgSaintapediaSuggestRequireCaptcha only —
 * LocalSettings.php, not a wiki page. This is an abuse/spam control, not
 * content curation, so (matching SaintapediaFeedback 1.9.0's identical call
 * for its own require-captcha setting) it does not get an on-wiki override:
 * a MediaWiki:-namespace page is editable by anyone holding editinterface,
 * with no deploy or code review, and could otherwise turn captcha off
 * wiki-wide.
 *
 * Uses ConfirmEdit + hCaptcha when available; fails closed when captcha is
 * required but ConfirmEdit/hCaptcha is not configured.
 */
class CaptchaGate {

	/** @var string Result of last decision, for diagnostics in API errors */
	private static string $lastFailReason = '';

	public static function getLastFailReason(): string {
		return self::$lastFailReason;
	}

	/**
	 * Effective "require captcha for this request" policy (before skip-rights).
	 * A null config value means "auto from mode".
	 */
	public static function isCaptchaEnabled( Config $config ): bool {
		$flag = $config->get( 'SaintapediaSuggestRequireCaptcha' );
		return $flag === null
			? $config->get( 'SaintapediaSuggestMode' ) !== 'enterprise'
			: (bool)$flag;
	}

	/**
	 * Whether this user must solve a captcha for this submit.
	 */
	public static function mustSolveCaptcha( Config $config, User $user ): bool {
		if ( !self::isCaptchaEnabled( $config ) ) {
			return false;
		}
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'ConfirmEdit' ) ) {
			// Still "must" — the API will fail closed.
			return true;
		}
		$captcha = ConfirmEditHooks::getInstance();
		if ( $captcha->canSkipCaptcha( $user, $config ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Verify captcha for a submit request. Returns true if allowed to proceed.
	 */
	public static function pass( Config $config, WebRequest $request, User $user ): bool {
		self::$lastFailReason = '';

		if ( !self::mustSolveCaptcha( $config, $user ) ) {
			return true;
		}

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'ConfirmEdit' ) ) {
			self::$lastFailReason = 'unavailable';
			return false;
		}

		// Check the hCaptcha site key first so misconfigured wikis fail
		// closed with a distinguishable reason rather than a generic failure.
		if ( self::getHCaptchaSiteKey() === '' ) {
			self::$lastFailReason = 'unavailable';
			return false;
		}

		$captcha = ConfirmEditHooks::getInstance();
		$captcha->setAction( 'saintapediasuggest' );
		$captcha->setTrigger( 'saintapediasuggest-submit' );

		if ( !$captcha->passCaptchaLimitedFromRequest( $request, $user ) ) {
			self::$lastFailReason = 'failed';
			return false;
		}

		return true;
	}

	public static function getHCaptchaSiteKey(): string {
		$services = MediaWikiServices::getInstance();

		try {
			$key = $services->getConfigFactory()
				->makeConfig( 'hcaptcha' )
				->get( 'HCaptchaSiteKey' );
			if ( is_string( $key ) && $key !== '' ) {
				return $key;
			}
		} catch ( \Throwable $e ) {
			// hCaptcha submodule may not be loaded
		}

		try {
			$key = $services->getMainConfig()->get( 'HCaptchaSiteKey' );
			if ( is_string( $key ) && $key !== '' ) {
				return $key;
			}
		} catch ( \Throwable $e ) {
			// not set
		}

		return '';
	}

	/**
	 * Expose captcha UI config and CSP when the widget may need hCaptcha.
	 *
	 * @return array{requireCaptcha:bool,captchaMisconfigured:bool,hCaptchaSiteKey:string}
	 */
	public static function prepareOutput( OutputPage $out, Config $config ): array {
		$user = $out->getUser();
		$require = self::mustSolveCaptcha( $config, $user );
		$siteKey = $require ? self::getHCaptchaSiteKey() : '';

		if ( $require && $siteKey !== '' && class_exists( HCaptcha::class ) ) {
			HCaptcha::addCSPSources( $out->getCSP() );
		}

		return [
			'requireCaptcha' => $require && $siteKey !== '',
			// If policy requires captcha but the key is missing, the widget
			// shows a configuration error instead of a broken submit button.
			'captchaMisconfigured' => $require && $siteKey === '',
			'hCaptchaSiteKey' => $siteKey,
		];
	}
}
