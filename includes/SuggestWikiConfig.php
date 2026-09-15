<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use MediaWiki\MediaWikiServices;
use Title;

/**
 * On-wiki overrides for non-secret operational knobs: rate limit,
 * notify-user list, captcha-required, widget on/off, and the Cargo
 * table/field allow-list.
 *
 * Mirrors SuggestAccess's MediaWiki:-page pattern: one page per setting,
 * PHP config is the fallback when the page is missing/empty, WAN-cached,
 * invalidated on save/delete/move via maybeInvalidate().
 *
 * Never use this for secrets (hCaptcha secret key, tokens) —
 * MediaWiki-namespace pages are readable by anyone even though editing is
 * restricted to editinterface, so only non-sensitive values belong here.
 */
class SuggestWikiConfig {

	public const CACHE_KEY = 'saintapediasuggest-wikiconfig';

	/**
	 * Registered on-wiki-overridable pages: config key that names the page
	 * (so it can be renamed in LocalSettings) => default DB key. Used by
	 * Hooks to invalidate cache on save/delete/move without hardcoding.
	 *
	 * @return array<string,string>
	 */
	public static function pages(): array {
		return [
			'SaintapediaSuggestRateLimitPage' => 'SaintapediaSuggest-ratelimit',
			'SaintapediaSuggestNotifyUsersPage' => 'SaintapediaSuggest-notify-users',
			'SaintapediaSuggestRequireCaptchaPage' => 'SaintapediaSuggest-require-captcha',
			'SaintapediaSuggestEnabledPage' => 'SaintapediaSuggest-enabled',
			'SaintapediaSuggestTablesPage' => 'SaintapediaSuggest-tables',
		];
	}

	/**
	 * False in the standalone unit-test bootstrap (no MediaWiki core loaded)
	 * and whenever a wiki-page read fails — callers fall back to the PHP
	 * config value rather than fatal/500 on the request.
	 */
	private static function servicesAvailable(): bool {
		return class_exists( MediaWikiServices::class, false );
	}

	private static function pageName( string $pageConfigKey, string $pageDefault ): string {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$name = $config->get( $pageConfigKey );
		return ( is_string( $name ) && $name !== '' ) ? $name : $pageDefault;
	}

	/**
	 * Raw trimmed page text plus whether the wiki-page read threw.
	 *
	 * A missing/empty page, or no MediaWiki services (standalone unit
	 * tests), is not a read failure — callers fall back to PHP. A cache/DB
	 * exception is a read failure so security knobs can fail closed instead
	 * of treating the overlay as empty.
	 *
	 * @return array{0: string, 1: bool, 2: string} [ text, readFailed, error ]
	 */
	private static function loadText( string $pageConfigKey, string $pageDefault ): array {
		if ( !self::servicesAvailable() ) {
			return [ '', false, '' ];
		}
		try {
			$pageName = self::pageName( $pageConfigKey, $pageDefault );
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

			$text = $cache->getWithSetCallback(
				$cache->makeKey( self::CACHE_KEY, md5( $pageName ) ),
				$cache::TTL_HOUR,
				static function () use ( $pageName ) {
					$title = Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
					if ( !$title || !$title->exists() ) {
						return '';
					}
					$wikipage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
					$content = $wikipage->getContent();
					if ( !$content ) {
						return '';
					}
					$text = method_exists( $content, 'getText' )
						? $content->getText()
						: $content->getTextForSearchIndex();
					return trim( (string)$text );
				}
			);
			return [ (string)$text, false, '' ];
		} catch ( \Throwable $e ) {
			return [ '', true, $e->getMessage() ];
		}
	}

	/**
	 * Warning text for an overlay read failure. Pure; unit-testable.
	 * Only knobs that pass $onReadError (captcha) actually fail closed.
	 */
	public static function overlayReadFailureMessage(
		string $pageConfigKey,
		bool $failClosed,
		string $detail = ''
	): string {
		$how = $failClosed ? 'failing closed' : 'using PHP value';
		$msg = "SaintapediaSuggest: wiki-config read failed for {$pageConfigKey}; {$how}.";
		if ( $detail !== '' ) {
			$msg .= ' ' . $detail;
		}
		return $msg;
	}

	private static function logOverlayReadFailure(
		string $pageConfigKey,
		bool $failClosed,
		string $detail
	): void {
		if ( function_exists( 'wfLogWarning' ) ) {
			wfLogWarning( self::overlayReadFailureMessage( $pageConfigKey, $failClosed, $detail ) );
		}
	}

	/**
	 * Normalized, deduplicated lines: same wiki-list "*" / inline "#"
	 * comment / blank-line handling as the access pages, via
	 * SuggestAccess::normalizeLine(). Pure; unit-testable.
	 *
	 * @return string[]
	 */
	public static function parseLines( string $text ): array {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$normalized = SuggestAccess::normalizeLine( $line );
			if ( $normalized !== null && !in_array( $normalized, $out, true ) ) {
				$out[] = $normalized;
			}
		}
		return $out;
	}

	/**
	 * Parse one token into true/false, or null when unrecognized.
	 * Accepts true/yes/on/1 and false/no/off/0 (case-insensitive).
	 * Pure; unit-testable.
	 */
	public static function parseBoolToken( string $value ): ?bool {
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, [ 'true', 'yes', 'on', '1' ], true ) ) {
			return true;
		}
		if ( in_array( $value, [ 'false', 'no', 'off', '0' ], true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Resolve a bool from overlay text. Pure; unit-testable.
	 *
	 * $readFailed is a cache/DB exception, not a missing page. When it is
	 * true and $onReadError is non-null, return $onReadError (captcha uses
	 * true so a blip cannot turn protection off). Otherwise missing/empty
	 * /unrecognized text keeps $phpValue.
	 */
	public static function resolveBool(
		string $text,
		bool $phpValue,
		bool $readFailed = false,
		?bool $onReadError = null
	): bool {
		if ( $readFailed ) {
			return $onReadError ?? $phpValue;
		}
		$lines = self::parseLines( $text );
		if ( !$lines ) {
			return $phpValue;
		}
		$parsed = self::parseBoolToken( $lines[0] );
		return $parsed ?? $phpValue;
	}

	/** Effective bool: on-wiki override wins when the page holds a recognizable value. */
	public static function effectiveBool(
		string $pageConfigKey,
		string $pageDefault,
		bool $phpValue,
		?bool $onReadError = null
	): bool {
		[ $text, $readFailed, $error ] = self::loadText( $pageConfigKey, $pageDefault );
		if ( $readFailed ) {
			self::logOverlayReadFailure( $pageConfigKey, $onReadError === true, $error );
		}
		return self::resolveBool( $text, $phpValue, $readFailed, $onReadError );
	}

	/**
	 * Resolve a non-negative int from overlay text. Pure; unit-testable.
	 * `0` is a valid override (reject every submit); empty/unrecognized
	 * text and read failures keep $phpValue.
	 */
	public static function resolveInt( string $text, int $phpValue, bool $readFailed = false ): int {
		if ( $readFailed ) {
			return $phpValue;
		}
		$lines = self::parseLines( $text );
		if ( !$lines || !ctype_digit( $lines[0] ) ) {
			return $phpValue;
		}
		return (int)$lines[0];
	}

	/** Effective int: on-wiki override wins when the page holds a non-negative integer. */
	public static function effectiveInt( string $pageConfigKey, string $pageDefault, int $phpValue ): int {
		[ $text, $readFailed, $error ] = self::loadText( $pageConfigKey, $pageDefault );
		if ( $readFailed ) {
			self::logOverlayReadFailure( $pageConfigKey, false, $error );
		}
		return self::resolveInt( $text, $phpValue, $readFailed );
	}

	/** Effective list (e.g. usernames): on-wiki override wins when the page has any lines. */
	public static function effectiveList( string $pageConfigKey, string $pageDefault, array $phpValue ): array {
		[ $text, $readFailed, $error ] = self::loadText( $pageConfigKey, $pageDefault );
		if ( $readFailed ) {
			self::logOverlayReadFailure( $pageConfigKey, false, $error );
			return $phpValue;
		}
		$lines = self::parseLines( $text );
		return $lines ?: $phpValue;
	}

	/**
	 * Parse "Table: field1, field2" / "Table: *" / bare "Table" lines into
	 * the raw shape CargoFieldRegistry::normalizeAllowList() accepts.
	 *
	 * Pure; unit-testable. Lines are expected to already be comment- and
	 * bullet-stripped by parseLines().
	 *
	 * @param string[] $lines
	 * @return array<string,true|string[]>
	 */
	public static function parseTableLines( array $lines ): array {
		$raw = [];
		foreach ( $lines as $line ) {
			$parts = explode( ':', $line, 2 );
			$table = trim( $parts[0] );
			if ( $table === '' ) {
				continue;
			}
			$raw[$table] = isset( $parts[1] ) && trim( $parts[1] ) !== ''
				? array_map( 'trim', explode( ',', $parts[1] ) )
				: true;
		}
		return $raw;
	}

	/**
	 * Effective Cargo table/field allow-list, in the raw (pre-normalize)
	 * shape: on-wiki override wins when the page has any recognizable
	 * lines, else the PHP default. Callers still run the result through
	 * CargoFieldRegistry::normalizeAllowList() themselves, so both sources
	 * get identical validation regardless of where the list came from.
	 *
	 * Unlike SaintapediaFeedback's access-control settings, this is not
	 * treated as security-sensitive enough to keep LocalSettings-only: it
	 * only decides which already-public Cargo fields get a "suggest a
	 * correction" affordance, and nothing here is auto-applied — every
	 * submission still lands in the review queue.
	 *
	 * @param array<string,true|string[]> $phpRawValue
	 * @return array<string,true|string[]>
	 */
	public static function effectiveTablesRaw( array $phpRawValue ): array {
		[ $text, $readFailed, $error ] = self::loadText(
			'SaintapediaSuggestTablesPage', 'SaintapediaSuggest-tables'
		);
		if ( $readFailed ) {
			self::logOverlayReadFailure( 'SaintapediaSuggestTablesPage', false, $error );
			return $phpRawValue;
		}
		$lines = self::parseLines( $text );
		if ( !$lines ) {
			return $phpRawValue;
		}
		return self::parseTableLines( $lines );
	}

	public static function getPageTitle( string $pageConfigKey, string $pageDefault ): ?Title {
		return Title::makeTitleSafe( NS_MEDIAWIKI, self::pageName( $pageConfigKey, $pageDefault ) );
	}

	public static function invalidate( string $pageConfigKey, string $pageDefault ): void {
		$pageName = self::pageName( $pageConfigKey, $pageDefault );
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cache->delete( $cache->makeKey( self::CACHE_KEY, md5( $pageName ) ) );
	}

	/** Invalidate whichever registered page this title matches, if any. */
	public static function maybeInvalidate( Title $title ): void {
		foreach ( self::pages() as $pageConfigKey => $pageDefault ) {
			$pageTitle = self::getPageTitle( $pageConfigKey, $pageDefault );
			if ( $pageTitle && $title->equals( $pageTitle ) ) {
				self::invalidate( $pageConfigKey, $pageDefault );
			}
		}
	}
}
