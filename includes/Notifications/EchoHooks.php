<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Notifications;

use MediaWiki\Extension\SaintapediaSuggest\SuggestAccess;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

/**
 * Registers the saintapediasuggest-new Echo event type and locates recipients.
 * Only reached when Extension:Echo is installed.
 */
class EchoHooks {

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent(
		&$notifications,
		&$notificationCategories,
		&$icons
	) {
		$notificationCategories['saintapediasuggest'] = [
			'priority' => 3,
			'tooltip'  => 'echo-pref-tooltip-saintapediasuggest',
		];

		$notifications['saintapediasuggest-new'] = [
			'category'           => 'saintapediasuggest',
			'group'              => 'neutral',
			'section'            => 'alert',
			'presentation-model' => SuggestPresentationModel::class,
			'bundle'             => [ 'web' => true, 'expand' => true ],
			'user-locators'      => [
				[ [ self::class, 'locateNotifiedUsers' ] ],
			],
		];
	}

	/**
	 * Recipients are resolved at submit time and carried on the event, but
	 * re-filtered here: access can be revoked between the submit and the
	 * notification being formatted, and the event carries the reader's
	 * proposed value.
	 *
	 * @param \EchoEvent|\MediaWiki\Extension\Notifications\Model\Event $event
	 * @return UserIdentity[]
	 */
	public static function locateNotifiedUsers( $event ): array {
		$extra = $event->getExtra();
		$ids = $extra['notify-user-ids'] ?? [];
		if ( !is_array( $ids ) || !$ids ) {
			return [];
		}
		$users = [];
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		foreach ( $ids as $id ) {
			$user = $userFactory->newFromId( (int)$id );
			if ( SuggestAccess::isPersistentAccount( $user ) && SuggestAccess::userCanManage( $user ) ) {
				$users[] = $user;
			}
		}
		return $users;
	}
}
