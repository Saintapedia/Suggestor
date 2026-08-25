<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use Title;
use User;

/**
 * Who may view/triage the suggestion dashboard.
 *
 * Configurable via a MediaWiki-namespace page (default:
 * MediaWiki:SaintapediaSuggest-access). One group name per line.
 *
 * Special tokens:
 * - sysop — administrators [default; matches saintapediasuggest-view]
 * - user  — any persistent named account (not temp / IP)
 * - *     — everyone including anons (rarely appropriate)
 * - autoconfirmed, editor, … — normal MediaWiki groups
 *
 * Lines starting with # or ; and blank lines are ignored.
 *
 * Default when the page is missing or empty: [ 'sysop' ].
 * Users holding saintapediasuggest-view via LocalSettings always pass.
 *
 * Mirrors SaintapediaFeedback's FeedbackAccess so an admin who has
 * configured one wiki already knows how this one behaves.
 */
class SuggestAccess {

	public const DEFAULT_GROUPS = [ 'sysop' ];

	public const DEFAULT_EMAIL_GROUPS = [ 'sysop' ];

	public const DEFAULT_EXPORT_GROUPS = [ 'sysop' ];

	public const CACHE_KEY = 'saintapediasuggest-access-groups';

	public const EMAIL_CACHE_KEY = 'saintapediasuggest-email-access-groups';

	public const EXPORT_CACHE_KEY = 'saintapediasuggest-export-access-groups';

	/**
	 * Named account with a durable identity (not anon, not a MW temp account).
	 *
	 * Temp users are isRegistered() === true on MW 1.39+; they must not get
	 * the "user" dashboard token or a stored sg_user_id. isTemp() is absent
	 * on some 1.39 builds — those users are treated as named if registered.
	 *
	 * @param object $user User / UserIdentity / test double
	 */
	public static function isPersistentAccount( $user ): bool {
		if ( !is_object( $user ) || !method_exists( $user, 'isRegistered' ) || !$user->isRegistered() ) {
			return false;
		}
		if ( method_exists( $user, 'isTemp' ) && $user->isTemp() ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether this user may open the dashboard / toolbox link.
	 */
	public static function userCanManage( UserIdentity $user ): bool {
		$userObj = $user instanceof User
			? $user
			: MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user );

		// Blocks revoke dashboard access. Mirrors the submit API: any block
		// (including partial) is enough to deny, so admins can rely on a
		// block alone under a broad access-page configuration.
		if ( self::userIsBlocked( $userObj ) ) {
			return false;
		}

		if ( $userObj->isAllowed( 'saintapediasuggest-view' ) ) {
			return true;
		}

		$effective = MediaWikiServices::getInstance()
			->getUserGroupManager()
			->getUserEffectiveGroups( $userObj );

		return self::groupsGrantAccess( self::getAllowedGroups(), $userObj, $effective );
	}

	/**
	 * Whether this user may see the optional contact-email field.
	 *
	 * Separate from userCanManage() so email can be locked to a smaller set
	 * even when the dashboard is opened up more broadly. Callers must gate
	 * on userCanManage() first — this only decides email visibility for
	 * someone who can already open the dashboard.
	 */
	public static function userCanViewEmail( UserIdentity $user ): bool {
		try {
			return self::userHasSecondaryAccess(
				$user,
				'saintapediasuggest-viewemail',
				[ self::class, 'getAllowedEmailGroups' ]
			);
		} catch ( \Throwable $e ) {
			// Isolated failure on the email-access page (separate cache key
			// from dashboard access): hide email instead of 500ing. A general
			// cache/DB outage still throws from userCanManage() first.
			self::logClosedFailure( 'userCanViewEmail', $e );
			return false;
		}
	}

	/**
	 * Whether this user may download the JSON export (bulk suggestion data).
	 *
	 * Separate from userCanManage() so a broader triage group does not
	 * automatically get bulk offline export.
	 */
	public static function userCanExport( UserIdentity $user ): bool {
		try {
			return self::userHasSecondaryAccess(
				$user,
				'saintapediasuggest-export',
				[ self::class, 'getAllowedExportGroups' ]
			);
		} catch ( \Throwable $e ) {
			self::logClosedFailure( 'userCanExport', $e );
			return false;
		}
	}

	private static function logClosedFailure( string $context, \Throwable $e ): void {
		if ( function_exists( 'wfLogWarning' ) ) {
			wfLogWarning( "SaintapediaSuggest: {$context} overlay read failed; denying. {$e->getMessage()}" );
		}
	}

	/**
	 * Email/export check without the fail-closed wrapper. Throws on a
	 * wiki-page read failure so callers can deny instead of 500.
	 *
	 * @param callable(): string[] $groupsFn
	 */
	private static function userHasSecondaryAccess(
		UserIdentity $user,
		string $right,
		callable $groupsFn
	): bool {
		$userObj = $user instanceof User
			? $user
			: MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user );

		if ( self::userIsBlocked( $userObj ) ) {
			return false;
		}

		if ( $userObj->isAllowed( $right ) ) {
			return true;
		}

		$effective = MediaWikiServices::getInstance()
			->getUserGroupManager()
			->getUserEffectiveGroups( $userObj );

		return self::groupsGrantAccess( $groupsFn(), $userObj, $effective );
	}

	/**
	 * Whether the access-page group list grants this identity.
	 *
	 * Ignores blocks and saintapediasuggest-view (applied in userCanManage).
	 * The `user` token matches named accounts only — not anons, not temps.
	 *
	 * @param string[] $groups
	 * @param object $user User / UserIdentity / test double
	 * @param string[] $effectiveGroups from UserGroupManager
	 */
	public static function groupsGrantAccess( array $groups, $user, array $effectiveGroups = [] ): bool {
		if ( !$groups ) {
			$groups = self::DEFAULT_GROUPS;
		}

		if ( in_array( '*', $groups, true ) ) {
			return true;
		}

		if ( in_array( 'user', $groups, true ) && self::isPersistentAccount( $user ) ) {
			return true;
		}

		foreach ( $groups as $g ) {
			if ( $g === 'user' || $g === '*' ) {
				continue;
			}
			if ( in_array( $g, $effectiveGroups, true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function userIsBlocked( User $user ): bool {
		try {
			if ( method_exists( $user, 'getBlock' ) ) {
				return (bool)$user->getBlock();
			}
		} catch ( \Throwable $e ) {
			// fall through
		}
		return false;
	}

	/**
	 * Groups currently allowed to manage suggestions.
	 *
	 * @return string[]
	 */
	public static function getAllowedGroups(): array {
		return self::getAllowedGroupsFor(
			'SaintapediaSuggestAccessPage',
			'SaintapediaSuggest-access',
			'SaintapediaSuggestAccessGroups',
			self::DEFAULT_GROUPS,
			self::CACHE_KEY
		);
	}

	/**
	 * Groups currently allowed to see the contact-email field.
	 *
	 * @return string[]
	 */
	public static function getAllowedEmailGroups(): array {
		return self::getAllowedGroupsFor(
			'SaintapediaSuggestEmailAccessPage',
			'SaintapediaSuggest-email-access',
			'SaintapediaSuggestEmailAccessGroups',
			self::DEFAULT_EMAIL_GROUPS,
			self::EMAIL_CACHE_KEY
		);
	}

	/**
	 * Groups currently allowed to export.
	 *
	 * @return string[]
	 */
	public static function getAllowedExportGroups(): array {
		return self::getAllowedGroupsFor(
			'SaintapediaSuggestExportAccessPage',
			'SaintapediaSuggest-export-access',
			'SaintapediaSuggestExportAccessGroups',
			self::DEFAULT_EXPORT_GROUPS,
			self::EXPORT_CACHE_KEY
		);
	}

	/**
	 * @param string $pageConfigKey Config var naming the MediaWiki-namespace page
	 * @param string $pageDefault Fallback DB key when that config var is unset
	 * @param string $groupsConfigKey Config var with the PHP-default group list
	 * @param string[] $groupsDefault Fallback when that config var is unset
	 * @param string $cacheKeyPrefix
	 * @return string[]
	 */
	private static function getAllowedGroupsFor(
		string $pageConfigKey,
		string $pageDefault,
		string $groupsConfigKey,
		array $groupsDefault,
		string $cacheKeyPrefix
	): array {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$cache = $services->getMainWANObjectCache();

		$pageName = $config->get( $pageConfigKey );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = $pageDefault;
		}

		$defaults = $config->get( $groupsConfigKey );
		if ( !is_array( $defaults ) || !$defaults ) {
			$defaults = $groupsDefault;
		}

		return $cache->getWithSetCallback(
			$cache->makeKey( $cacheKeyPrefix, md5( $pageName ) ),
			$cache::TTL_HOUR,
			static function () use ( $pageName, $defaults ) {
				return self::loadGroupsFromPage( $pageName, $defaults );
			}
		);
	}

	/**
	 * @param string $pageName DB key under NS_MEDIAWIKI (no namespace prefix)
	 * @param string[] $defaults
	 * @return string[]
	 */
	public static function loadGroupsFromPage( string $pageName, array $defaults ): array {
		$title = Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
		if ( !$title || !$title->exists() ) {
			return array_values( $defaults );
		}

		$services = MediaWikiServices::getInstance();
		$wikipage = $services->getWikiPageFactory()->newFromTitle( $title );
		$content = $wikipage->getContent();
		if ( !$content ) {
			return array_values( $defaults );
		}

		$text = method_exists( $content, 'getText' )
			? $content->getText()
			: $content->getTextForSearchIndex();

		$groups = self::parseGroupList( (string)$text );
		if ( !$groups ) {
			return array_values( $defaults );
		}
		return $groups;
	}

	/**
	 * Normalize one wiki-page line: skip blank/comment-only lines, strip a
	 * leading wiki-list "*" marker (keeping a lone "*" as the everyone
	 * token) and an inline "#" comment. Returns null when nothing is left.
	 *
	 * Pure; shared by parseGroupList() and SuggestWikiConfig so both accept
	 * the same on-wiki page conventions.
	 */
	public static function normalizeLine( string $line ): ?string {
		$line = trim( $line );
		if ( $line === '' || $line[0] === '#' || $line[0] === ';' ) {
			return null;
		}
		// Allow "* user" wiki-list markup. A line that is only "*" (the
		// documented everyone-including-anons token) must survive the strip.
		$raw = $line;
		$line = preg_replace( '/^\*+\s*/', '', $line );
		$line = trim( $line );
		if ( $line === '' && preg_match( '/^\*+$/', $raw ) ) {
			$line = '*';
		}
		if ( strpos( $line, '#' ) !== false ) {
			$line = trim( substr( $line, 0, strpos( $line, '#' ) ) );
		}
		return $line === '' ? null : $line;
	}

	/**
	 * Parse wiki page body into group tokens (pure; unit-testable).
	 *
	 * @return string[]
	 */
	public static function parseGroupList( string $text ): array {
		$groups = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$normalized = self::normalizeLine( $line );
			if ( $normalized !== null ) {
				$groups[] = $normalized;
			}
		}
		$out = [];
		foreach ( $groups as $g ) {
			if ( !in_array( $g, $out, true ) ) {
				$out[] = $g;
			}
		}
		return $out;
	}

	/** Drop WAN cache after the access page is edited. */
	public static function invalidateCache(): void {
		self::invalidateCacheFor(
			'SaintapediaSuggestAccessPage',
			'SaintapediaSuggest-access',
			self::CACHE_KEY
		);
	}

	/** Drop WAN cache after the email-access page is edited. */
	public static function invalidateEmailCache(): void {
		self::invalidateCacheFor(
			'SaintapediaSuggestEmailAccessPage',
			'SaintapediaSuggest-email-access',
			self::EMAIL_CACHE_KEY
		);
	}

	/** Drop WAN cache after the export-access page is edited. */
	public static function invalidateExportCache(): void {
		self::invalidateCacheFor(
			'SaintapediaSuggestExportAccessPage',
			'SaintapediaSuggest-export-access',
			self::EXPORT_CACHE_KEY
		);
	}

	private static function invalidateCacheFor(
		string $pageConfigKey,
		string $pageDefault,
		string $cacheKeyPrefix
	): void {
		$services = MediaWikiServices::getInstance();
		$pageName = $services->getMainConfig()->get( $pageConfigKey );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = $pageDefault;
		}
		$cache = $services->getMainWANObjectCache();
		$cache->delete( $cache->makeKey( $cacheKeyPrefix, md5( $pageName ) ) );
	}

	/** Title of the dashboard-access configuration page (for help links). */
	public static function getAccessPageTitle(): ?Title {
		return self::pageTitleFor( 'SaintapediaSuggestAccessPage', 'SaintapediaSuggest-access' );
	}

	/** Title of the email-access configuration page (for help links). */
	public static function getEmailAccessPageTitle(): ?Title {
		return self::pageTitleFor( 'SaintapediaSuggestEmailAccessPage', 'SaintapediaSuggest-email-access' );
	}

	/** Title of the export-access configuration page (for help links). */
	public static function getExportAccessPageTitle(): ?Title {
		return self::pageTitleFor( 'SaintapediaSuggestExportAccessPage', 'SaintapediaSuggest-export-access' );
	}

	private static function pageTitleFor( string $pageConfigKey, string $pageDefault ): ?Title {
		$pageName = MediaWikiServices::getInstance()->getMainConfig()->get( $pageConfigKey );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = $pageDefault;
		}
		return Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
	}
}
