<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use SpecialPage;

/**
 * Echo presentation for new field-suggestion alerts.
 * Only loaded when Echo is present.
 */
class SuggestPresentationModel extends EchoEventPresentationModel {

	public function getIconType() {
		return 'placeholder';
	}

	public function getHeaderMessage() {
		$msg = $this->msg( 'notification-header-saintapediasuggest-new' );
		$title = $this->event->getTitle();
		$msg->params( $title ? $title->getPrefixedText() : '' );
		return $msg;
	}

	public function getBodyMessage() {
		$extra = $this->event->getExtra();
		$field = (string)( $extra['cargo-field'] ?? '' );
		$table = (string)( $extra['cargo-table'] ?? '' );
		$value = (string)( $extra['suggested-value'] ?? '' );

		if ( $field === '' ) {
			return false;
		}
		$target = $table !== '' ? "{$table}.{$field}" : $field;

		if ( $value !== '' ) {
			return $this->msg( 'notification-body-saintapediasuggest-new' )
				->params( $target, $value );
		}
		return $this->msg( 'notification-body-saintapediasuggest-new-field' )
			->params( $target );
	}

	public function getPrimaryLink() {
		$title = $this->event->getTitle();
		$url = $title
			? SpecialPage::getTitleFor( 'SaintapediaSuggest', (string)$title->getArticleID() )->getFullURL()
			: SpecialPage::getTitleFor( 'SaintapediaSuggest' )->getFullURL();

		return [
			'url'   => $url,
			'label' => $this->msg( 'notification-link-saintapediasuggest-new' )->text(),
		];
	}
}
