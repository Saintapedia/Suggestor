<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Api;

use ApiBase;
use MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry;
use MediaWiki\Extension\SaintapediaSuggest\CaptchaGate;
use MediaWiki\Extension\SaintapediaSuggest\SuggestAccess;
use MediaWiki\Extension\SaintapediaSuggest\SuggestNotifier;
use MediaWiki\Extension\SaintapediaSuggest\SuggestionStore;
use MediaWiki\Extension\SaintapediaSuggest\SuggestWikiConfig;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * action=saintapediasuggest — submit one Cargo field suggestion.
 *
 * Open to anonymous readers by design; every submit passes, in order:
 * block check → title/namespace validation → allow-list validation →
 * value validation → captcha → per-IP rate limit.
 *
 * Cheap checks run before the captcha deliberately: a malformed request must
 * not burn the reader's one-time hCaptcha token or cost an outbound
 * siteverify round-trip.
 *
 * This module never returns a stored contact email, and never writes to
 * Cargo — a suggestion is a review queue entry, nothing more.
 */
class ApiSaintapediaSuggestSubmit extends ApiBase {

	private SuggestionStore $store;
	private CargoFieldRegistry $registry;
	private TitleFactory $titleFactory;

	public function __construct(
		$main,
		$moduleName,
		SuggestionStore $store,
		CargoFieldRegistry $registry,
		TitleFactory $titleFactory
	) {
		parent::__construct( $main, $moduleName );
		$this->store = $store;
		$this->registry = $registry;
		$this->titleFactory = $titleFactory;
	}

	public function execute(): void {
		$params  = $this->extractRequestParams();
		$config  = $this->getConfig();
		$request = $this->getRequest();
		$user    = $this->getUser();
		$mode    = $config->get( 'SaintapediaSuggestMode' );

		// Deny blocked users, including partial blocks — matches MediaWiki
		// core's convention for write APIs.
		$block = $this->getAuthority()->getBlock();
		if ( $block ) {
			$this->dieBlocked( $block );
		}

		if ( !$this->widgetEnabled( $config ) ) {
			$this->dieWithError( 'saintapediasuggest-error-disabled', 'sps-disabled' );
		}

		$title = $this->titleFactory->newFromID( (int)$params['pageid'] );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError( 'apierror-invalidtitle' );
		}

		$allowedNamespaces = $config->get( 'SaintapediaSuggestNamespaces' );
		if ( !is_array( $allowedNamespaces )
			|| !in_array( $title->getNamespace(), $allowedNamespaces, true )
		) {
			$this->dieWithError( 'saintapediasuggest-error-namespace', 'sps-namespace' );
		}

		// The (table, field) pair is re-checked against the allow-list and
		// the live Cargo schema. Nothing about the target is trusted from
		// the request beyond these two strings.
		$cargoTable = trim( (string)$params['table'] );
		$cargoField = trim( (string)$params['field'] );
		if ( !$this->registry->isAllowed( $cargoTable, $cargoField ) ) {
			$this->dieWithError( 'saintapediasuggest-error-nofield', 'sps-nofield' );
		}

		// The current value is snapshotted server-side. A client-supplied
		// "current value" would let a submitter fabricate the before-state
		// a reviewer sees.
		$currentValue = $this->registry->getCurrentValue(
			$title->getArticleID(),
			$cargoTable,
			$cargoField
		);
		if ( $currentValue === null ) {
			// Allow-listed, but this page has no row in that table.
			$this->dieWithError( 'saintapediasuggest-error-nofield', 'sps-nofield' );
		}

		$maxLen = (int)$config->get( 'SaintapediaSuggestMaxValueLength' );
		$suggested = trim( (string)$params['suggestedvalue'] );
		if ( $suggested === '' ) {
			$this->dieWithError( 'saintapediasuggest-error-novalue', 'sps-novalue' );
		}
		if ( $maxLen > 0 && mb_strlen( $suggested ) > $maxLen ) {
			$suggested = mb_substr( $suggested, 0, $maxLen );
		}

		// A "suggestion" identical to what is already stored is noise in the
		// triage queue, so reject it before it costs a captcha or a row.
		if ( $suggested === trim( $currentValue ) ) {
			$this->dieWithError( 'saintapediasuggest-error-unchanged', 'sps-unchanged' );
		}

		$comment = $params['comment'] ?? null;
		if ( $comment !== null ) {
			$comment = trim( $comment );
			$commentMax = $mode === 'enterprise' ? 5000 : 500;
			if ( mb_strlen( $comment ) > $commentMax ) {
				$comment = mb_substr( $comment, 0, $commentMax );
			}
			if ( $comment === '' ) {
				$comment = null;
			}
		}

		// hCaptcha / ConfirmEdit — open to anons, fails closed when required
		// but unconfigured.
		if ( !CaptchaGate::pass( $config, $request, $user ) ) {
			if ( CaptchaGate::getLastFailReason() === 'unavailable' ) {
				$this->dieWithError(
					'saintapediasuggest-error-captcha-unavailable',
					'sps-captcha-unavailable'
				);
			}
			$this->dieWithError( 'saintapediasuggest-error-captcha', 'sps-captcha' );
		}

		// Rate limiting keys on a salted hash; the raw IP is never stored.
		$ipHash = hash(
			'sha256',
			$request->getIP() . $config->get( 'SecretKey' )
		);
		$phpLimit = $mode === 'enterprise'
			? $config->get( 'SaintapediaSuggestEnterpriseRateLimit' )
			: $config->get( 'SaintapediaSuggestRateLimit' );
		$limit = SuggestWikiConfig::effectiveInt(
			'SaintapediaSuggestRateLimitPage',
			'SaintapediaSuggest-ratelimit',
			(int)$phpLimit
		);

		$enableEmail = $mode === 'enterprise' || $config->get( 'SaintapediaSuggestEnableEmail' );
		$contactEmail = null;
		if ( $enableEmail && isset( $params['email'] ) ) {
			$email = trim( $params['email'] );
			if ( $email !== '' && filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$contactEmail = $email;
			}
		}

		$id = $this->store->tryInsertUnderLimit( [
			'pageId'         => $title->getArticleID(),
			'namespace'      => $title->getNamespace(),
			'title'          => $title->getDBkey(),
			'cargoTable'     => $cargoTable,
			'cargoField'     => $cargoField,
			'currentValue'   => $currentValue,
			'suggestedValue' => $suggested,
			'comment'        => $comment,
			'userId'         => SuggestAccess::isPersistentAccount( $user ) ? $user->getId() : null,
			'ipHash'         => $ipHash,
			'contactEmail'   => $contactEmail,
			'mode'           => $mode,
		], $limit );

		if ( $id === null ) {
			$this->dieWithError( 'saintapediasuggest-error-ratelimit', 'sps-ratelimit' );
		}

		SuggestNotifier::notifyNew( $id, $title, $cargoTable, $cargoField, $suggested, $user );

		// Deliberately minimal: an id and a success flag. No email, no stored
		// row echoed back — this endpoint is readable by anyone.
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'id'     => $id,
		] );
	}

	private function widgetEnabled( $config ): bool {
		return SuggestWikiConfig::effectiveBool(
			'SaintapediaSuggestEnabledPage',
			'SaintapediaSuggest-enabled',
			(bool)$config->get( 'SaintapediaSuggestEnabled' )
		);
	}

	public function getAllowedParams(): array {
		return [
			'pageid' => [
				ParamValidator::PARAM_TYPE     => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'table' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'field' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'suggestedvalue' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'comment' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'email' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			// hCaptcha token (ConfirmEdit's HCaptcha also accepts h-captcha-response)
			'captchaWord' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'captchaword' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	protected function getExamplesMessages(): array {
		return [
			'action=saintapediasuggest&pageid=1&table=Parishes&field=Phone'
				. '&suggestedvalue=555-0100&token=123ABC'
				=> 'apihelp-saintapediasuggest-example-1',
		];
	}

	public function needsToken(): string {
		return 'csrf';
	}

	public function isWriteMode(): bool {
		return true;
	}

	public function mustBePosted(): bool {
		return true;
	}
}
