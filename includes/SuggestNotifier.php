<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use Config;
use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use Title;
use User;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Optional notifications when a suggestion is submitted.
 *
 * - Echo to named users (config list / on-wiki list)
 * - Echo to page watchers who may triage (config flag)
 * - Email to one optional address
 *
 * All soft dependencies: a failure here never fails the submit itself.
 */
class SuggestNotifier {

	/** Upper bound on watchlist rows read (popular articles). */
	private const WATCHER_SCAN_CAP = 1000;

	/** Max Echo recipients from the watchlist after access filtering. */
	private const MAX_WATCHER_RECIPIENTS = 100;

	/**
	 * @param int $suggestionId
	 * @param Title $title Page the suggestion is about
	 * @param string $cargoTable
	 * @param string $cargoField
	 * @param string $suggestedValue
	 * @param User $agent Submitting user (may be anon)
	 */
	public static function notifyNew(
		int $suggestionId,
		Title $title,
		string $cargoTable,
		string $cargoField,
		string $suggestedValue,
		User $agent
	): void {
		try {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			$recipients = self::collectRecipientIds( $config, $title, $agent );
			if ( $recipients ) {
				self::createEchoEvent(
					$suggestionId, $title, $cargoTable, $cargoField, $suggestedValue, $agent, $recipients
				);
			}
			self::notifyEmail( $config, $suggestionId, $title, $cargoTable, $cargoField, $suggestedValue );
		} catch ( \Throwable $e ) {
			wfDebugLog( 'SaintapediaSuggest', 'notifyNew failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @return int[] user ids
	 */
	private static function collectRecipientIds( Config $config, Title $title, User $agent ): array {
		$ids = [];
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		$phpNames = $config->get( 'SaintapediaSuggestNotifyUsers' );
		$names = SuggestWikiConfig::effectiveList(
			'SaintapediaSuggestNotifyUsersPage',
			'SaintapediaSuggest-notify-users',
			is_array( $phpNames ) ? $phpNames : []
		);
		foreach ( $names as $name ) {
			if ( !is_string( $name ) || $name === '' ) {
				continue;
			}
			$user = $userFactory->newFromName( $name );
			if ( $user && SuggestAccess::isPersistentAccount( $user ) && SuggestAccess::userCanManage( $user ) ) {
				$ids[] = $user->getId();
			}
		}

		// Watchers only if they may triage — otherwise Echo would leak the
		// reader's proposed value to users who cannot open the dashboard.
		if ( $config->get( 'SaintapediaSuggestNotifyWatchers' ) ) {
			$watcherHits = 0;
			foreach ( self::getWatcherUserIds( $title ) as $wid ) {
				$user = $userFactory->newFromId( (int)$wid );
				if ( SuggestAccess::isPersistentAccount( $user ) && SuggestAccess::userCanManage( $user ) ) {
					$ids[] = (int)$wid;
					$watcherHits++;
					if ( $watcherHits >= self::MAX_WATCHER_RECIPIENTS ) {
						break;
					}
				}
			}
		}

		// De-dupe; never notify the submitter about their own suggestion.
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( SuggestAccess::isPersistentAccount( $agent ) ) {
			$agentId = $agent->getId();
			$ids = array_values( array_filter(
				$ids,
				static function ( $id ) use ( $agentId ) {
					return (int)$id !== $agentId;
				}
			) );
		}
		return $ids;
	}

	/**
	 * Users watching this title. Callers must still filter by
	 * SuggestAccess::userCanManage() before sending protected content.
	 *
	 * @return int[]
	 */
	private static function getWatcherUserIds( Title $title ): array {
		/** @var ILoadBalancer $lb */
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $lb->getConnection( DB_REPLICA );
		$res = $dbr->select(
			'watchlist',
			[ 'wl_user' ],
			[
				'wl_namespace' => $title->getNamespace(),
				'wl_title'     => $title->getDBkey(),
			],
			__METHOD__,
			[
				'DISTINCT' => true,
				'ORDER BY' => 'wl_user',
				'LIMIT'    => self::WATCHER_SCAN_CAP,
			]
		);
		$ids = [];
		foreach ( $res as $row ) {
			$ids[] = (int)$row->wl_user;
		}
		return $ids;
	}

	/**
	 * @param int[] $recipients
	 */
	private static function createEchoEvent(
		int $suggestionId,
		Title $title,
		string $cargoTable,
		string $cargoField,
		string $suggestedValue,
		User $agent,
		array $recipients
	): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) || !$recipients ) {
			return;
		}
		$eventClass = class_exists( \MediaWiki\Extension\Notifications\Model\Event::class )
			? \MediaWiki\Extension\Notifications\Model\Event::class
			: ( class_exists( \EchoEvent::class ) ? \EchoEvent::class : null );
		if ( !$eventClass ) {
			return;
		}

		$eventClass::create( [
			'type'  => 'saintapediasuggest-new',
			'title' => $title,
			'agent' => $agent,
			'extra' => [
				'suggestion-id'   => $suggestionId,
				'cargo-table'     => $cargoTable,
				'cargo-field'     => $cargoField,
				'suggested-value' => mb_substr( $suggestedValue, 0, 200 ),
				'notify-user-ids' => $recipients,
			],
		] );
	}

	private static function notifyEmail(
		Config $config,
		int $suggestionId,
		Title $title,
		string $cargoTable,
		string $cargoField,
		string $suggestedValue
	): void {
		$to = $config->get( 'SaintapediaSuggestNotifyEmail' );
		if ( !is_string( $to ) || $to === '' || !filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			return;
		}

		$dashboard = SpecialPage::getTitleFor( 'SaintapediaSuggest' )->getFullURL();
		$subject = '[SaintapediaSuggest] ' . $cargoTable . '.' . $cargoField
			. ' on ' . $title->getPrefixedText();
		$body = "New field suggestion (#{$suggestionId})\n\n"
			. "Page: {$title->getPrefixedText()}\n"
			. "URL: {$title->getFullURL()}\n"
			. "Cargo target: {$cargoTable}.{$cargoField}\n"
			. 'Suggested value: ' . mb_substr( $suggestedValue, 0, 500 ) . "\n\n"
			. "Dashboard: {$dashboard}\n";

		$from = $config->get( 'PasswordSender' );
		if ( !is_string( $from ) || $from === '' ) {
			$from = 'wiki@localhost';
		}

		if ( class_exists( \MailAddress::class ) ) {
			\UserMailer::send(
				new \MailAddress( $to ),
				new \MailAddress( $from ),
				$subject,
				$body
			);
		}
	}
}
