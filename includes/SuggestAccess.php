<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use User;

/**
 * Who may view/triage the suggestion dashboard.
 *
 * Configured from LocalSettings.php only (2026-09-15, matching
 * SaintapediaFeedback's F-08 fix in its 2026-09-10 review): dashboard,
 * email, and export access used to also be overridable from
 * MediaWiki-namespace pages, editable by anyone holding editinterface with
 * no deploy or code review. That on-wiki override is removed entirely for
 * these three; only $wgSaintapediaSuggestAccessGroups / EmailAccessGroups /
 * ExportAccessGroups (below) are consulted now. This shipped before
 * Suggestor's first production deploy, so there is no upgrade concern —
 * nothing was ever relying on the removed pages. (Lower-stakes operational
 * settings — the Cargo table/field allow-list, notify list, enabled —
 * remain wiki-overridable; see SuggestWikiConfig.)
 *
 * Special tokens:
 * - sysop — administrators [default; matches saintapediasuggest-view]
 * - user  — any persistent named account (not temp / IP)
 * - *     — everyone including anons (rarely appropriate; never honored for
 *           email access — see getAllowedEmailGroups())
 * - autoconfirmed, editor, … — normal MediaWiki groups
 *
 * Default when a *Groups config var is unset or empty: [ 'sysop' ] for all
 * three (dashboard, email, export). Users holding saintapediasuggest-view
 * via LocalSettings always pass.
 */
class SuggestAccess {

	public const DEFAULT_GROUPS = [ 'sysop' ];

	public const DEFAULT_EMAIL_GROUPS = [ 'sysop' ];

	public const DEFAULT_EXPORT_GROUPS = [ 'sysop' ];

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
		// block alone under a broad access-group configuration.
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
	 * someone who can already open the dashboard. getAllowedEmailGroups()
	 * never honors a "*" token: contact email must never be visible to
	 * anonymous/everyone, regardless of how it's configured.
	 */
	public static function userCanViewEmail( UserIdentity $user ): bool {
		try {
			return self::userHasSecondaryAccess(
				$user,
				'saintapediasuggest-viewemail',
				[ self::class, 'getAllowedEmailGroups' ]
			);
		} catch ( \Throwable $e ) {
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
			wfLogWarning( "SaintapediaSuggest: {$context} failed; denying. {$e->getMessage()}" );
		}
	}

	/**
	 * Email/export check without the fail-closed wrapper. $groupsFn reads
	 * plain LocalSettings.php config now (no wiki-page IO), so this should
	 * not throw in practice; the try/catch in the two callers is kept as
	 * defense-in-depth rather than removed.
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
	 * Whether the configured group list grants this identity.
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
	 * Groups currently allowed to manage suggestions (from
	 * $wgSaintapediaSuggestAccessGroups, or DEFAULT_GROUPS when unset/empty).
	 *
	 * @return string[]
	 */
	public static function getAllowedGroups(): array {
		return self::configuredGroups( 'SaintapediaSuggestAccessGroups', self::DEFAULT_GROUPS );
	}

	/**
	 * Groups currently allowed to see the contact-email field (from
	 * $wgSaintapediaSuggestEmailAccessGroups, or DEFAULT_EMAIL_GROUPS when
	 * unset/empty). Independent of getAllowedGroups(). A "*" token is never
	 * honored here: contact email must never be visible to
	 * anonymous/everyone, so it is dropped before the list reaches
	 * groupsGrantAccess() — even if it came from LocalSettings.php.
	 *
	 * @return string[]
	 */
	public static function getAllowedEmailGroups(): array {
		return self::withoutPublicWildcard(
			self::configuredGroups( 'SaintapediaSuggestEmailAccessGroups', self::DEFAULT_EMAIL_GROUPS ),
			'SaintapediaSuggestEmailAccessGroups'
		);
	}

	/**
	 * Groups currently allowed to export (from
	 * $wgSaintapediaSuggestExportAccessGroups, or DEFAULT_EXPORT_GROUPS when
	 * unset/empty). Independent of getAllowedGroups().
	 *
	 * @return string[]
	 */
	public static function getAllowedExportGroups(): array {
		return self::configuredGroups( 'SaintapediaSuggestExportAccessGroups', self::DEFAULT_EXPORT_GROUPS );
	}

	/**
	 * @param string $configKey
	 * @param string[] $default
	 * @return string[]
	 */
	private static function configuredGroups( string $configKey, array $default ): array {
		$groups = MediaWikiServices::getInstance()->getMainConfig()->get( $configKey );
		return ( is_array( $groups ) && $groups ) ? array_values( $groups ) : $default;
	}

	/**
	 * Drops a "*" (everyone including anonymous) token from a group list,
	 * logging when it does so. Groups otherwise pass through
	 * groupsGrantAccess() unfiltered; this is the one place "*" is refused
	 * outright rather than just discouraged in documentation.
	 * Pure aside from the log call; unit-testable.
	 *
	 * @param string[] $groups
	 * @return string[]
	 */
	public static function withoutPublicWildcard( array $groups, string $configKey ): array {
		if ( !in_array( '*', $groups, true ) ) {
			return $groups;
		}
		if ( function_exists( 'wfLogWarning' ) ) {
			wfLogWarning(
				"SaintapediaSuggest: {$configKey} included '*' (everyone, including anonymous "
					. "readers). Contact-email visibility can never be made public; ignoring '*' "
					. 'for this setting.'
			);
		}
		return array_values( array_filter( $groups, static fn ( $g ) => $g !== '*' ) );
	}

	/**
	 * Normalize one wiki-page line: skip blank/comment-only lines, strip a
	 * leading wiki-list "*" marker (keeping a lone "*" as the everyone
	 * token) and an inline "#" comment. Returns null when nothing is left.
	 *
	 * Pure; shared by SuggestWikiConfig's line parsing so every remaining
	 * wiki-overridable setting accepts the same on-wiki page conventions.
	 * (Dashboard/email/export access no longer read a wiki page at all —
	 * see the class docblock — but this helper is still load-bearing for
	 * the settings that do.)
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
}
