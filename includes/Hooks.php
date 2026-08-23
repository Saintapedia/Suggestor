<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use Config;
use ExtensionRegistry;
use MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry;
use MediaWiki\Title\Title;
use OutputPage;
use Skin;
use SpecialPage;

/**
 * Request-time hook handlers.
 *
 * Registered as an object handler (extension.json "HookHandlers") so the
 * Cargo registry and the store arrive by injection instead of a
 * MediaWikiServices::getInstance() lookup inside each method.
 *
 * The core hook interfaces are deliberately not implemented: BeforePageDisplayHook
 * moved from MediaWiki\Hook to MediaWiki\Output\Hook after 1.39, so a hard
 * `implements` would pin this extension to one MediaWiki version. HookContainer
 * dispatches on method name, so plain handler methods work across 1.39–1.43.
 */
class Hooks {

	private Config $config;
	private CargoFieldRegistry $registry;
	private SuggestionStore $store;

	public function __construct(
		Config $config,
		CargoFieldRegistry $registry,
		SuggestionStore $store
	) {
		$this->config = $config;
		$this->registry = $registry;
		$this->store = $store;
	}

	/**
	 * Load the reader widget and hand it this page's suggestable Cargo fields.
	 *
	 * The field list is computed server-side and exposed through mw.config so
	 * the widget never has to ask what it may target — and so a tampered
	 * client list is still re-validated by the API on submit.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || $title->isSpecialPage() || !$title->exists() ) {
			return;
		}

		$allowedNamespaces = $this->config->get( 'SaintapediaSuggestNamespaces' );
		if ( !is_array( $allowedNamespaces )
			|| !in_array( $title->getNamespace(), $allowedNamespaces, true )
		) {
			return;
		}

		// Only on a plain view — not edit, history, diff, etc.
		if ( $out->getRequest()->getVal( 'action', 'view' ) !== 'view' ) {
			return;
		}

		if ( !$this->widgetEnabled() ) {
			return;
		}

		// The registry is deliberately free of MediaWiki's message system, so
		// the localised "Row N" fallback is injected from here.
		$context = $out->getContext();
		$fields = $this->registry->getSuggestableFields(
			$title->getArticleID(),
			static function ( $ordinal ) use ( $context ) {
				return $context->msg( 'saintapediasuggest-row-ordinal' )
					->numParams( $ordinal )->text();
			}
		);
		if ( !$fields ) {
			// No Cargo row, or nothing allow-listed for this page: render no
			// widget at all rather than an empty picker.
			return;
		}

		$mode = $this->config->get( 'SaintapediaSuggestMode' );
		$enableEmail = $mode === 'enterprise' || $this->config->get( 'SaintapediaSuggestEnableEmail' );
		$captcha = CaptchaGate::prepareOutput( $out, $this->config );

		$out->addJsConfigVars( [
			// SaintapediaFeedback puts its own floating button in the
			// bottom-right corner of the same articles. When both are
			// installed, ours stacks above it instead of landing underneath
			// it — its z-index is higher, so an overlap hides ours entirely.
			'spsStacked'              => ExtensionRegistry::getInstance()
				->isLoaded( 'SaintapediaFeedback' ),
			'spsMode'                 => $mode,
			'spsPageId'               => $title->getArticleID(),
			'spsPageTitle'            => $title->getPrefixedText(),
			'spsFields'               => $fields,
			'spsMaxValueLength'       => (int)$this->config->get( 'SaintapediaSuggestMaxValueLength' ),
			'spsEnableEmail'          => (bool)$enableEmail,
			'spsRequireCaptcha'       => $captcha['requireCaptcha'],
			'spsCaptchaMisconfigured' => $captcha['captchaMisconfigured'],
			'spsHCaptchaSiteKey'      => $captcha['hCaptchaSiteKey'],
		] );

		$out->addModules( 'ext.saintapediasuggest.widget' );
	}

	/**
	 * Toolbox: a reader entry point to open the widget, plus the triage
	 * dashboard link for users who may manage suggestions.
	 *
	 * @param Skin $skin
	 * @param array &$sidebar
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$title = $skin->getTitle();
		if ( !$title || !$title->exists() || $title->isSpecialPage() ) {
			return;
		}

		$allowedNamespaces = $this->config->get( 'SaintapediaSuggestNamespaces' );
		if ( !is_array( $allowedNamespaces )
			|| !in_array( $title->getNamespace(), $allowedNamespaces, true )
		) {
			return;
		}

		$isView = $skin->getRequest()->getVal( 'action', 'view' ) === 'view';
		if ( $isView && $this->widgetEnabled() ) {
			$sidebar['TOOLBOX']['saintapediasuggest-widget'] = [
				'id'   => 't-saintapediasuggest-widget',
				'href' => '#sps-suggest',
				'text' => $skin->msg( 'saintapediasuggest-button-label' )->text(),
			];
		}

		if ( !SuggestAccess::userCanManage( $skin->getUser() ) ) {
			return;
		}

		$counts = [ 'new' => 0, 'open' => 0 ];
		try {
			$counts = $this->store->getPageCounts( $title->getArticleID() );
		} catch ( \Throwable $e ) {
			// Table may not exist yet (extension enabled before update.php).
		}

		$text = $skin->msg( 'saintapediasuggest-toolbox' )->text();
		if ( !empty( $counts['new'] ) ) {
			$text = $skin->msg( 'saintapediasuggest-toolbox-count' )
				->numParams( (int)$counts['new'] )
				->text();
		} elseif ( !empty( $counts['open'] ) ) {
			$text = $skin->msg( 'saintapediasuggest-toolbox-open' )
				->numParams( (int)$counts['open'] )
				->text();
		}

		$sidebar['TOOLBOX']['saintapediasuggest'] = [
			'id'   => 't-saintapediasuggest',
			'href' => SpecialPage::getTitleFor(
				'SaintapediaSuggest',
				(string)$title->getArticleID()
			)->getLocalURL(),
			'text' => $text,
		];
	}

	/** Master on/off for the reader widget (PHP config + on-wiki override). */
	private function widgetEnabled(): bool {
		return SuggestWikiConfig::effectiveBool(
			'SaintapediaSuggestEnabledPage',
			'SaintapediaSuggest-enabled',
			(bool)$this->config->get( 'SaintapediaSuggestEnabled' )
		);
	}

	/**
	 * Invalidate the relevant cache when any access-config or on-wiki
	 * operational-setting page is edited.
	 *
	 * @param \WikiPage $wikiPage
	 * @param mixed $user
	 * @param string $summary
	 * @param int $flags
	 * @param mixed $revisionRecord
	 * @param mixed $editResult
	 */
	public function onPageSaveComplete(
		$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	): void {
		self::maybeInvalidateConfigCaches( $wikiPage->getTitle() );
	}

	/**
	 * Deleting a config page must reset to PHP defaults immediately rather
	 * than waiting out the cache TTL.
	 *
	 * @param mixed $page
	 * @param mixed $deleter
	 * @param string $reason
	 * @param int $pageID
	 * @param mixed $deletedRev
	 * @param mixed $logEntry
	 * @param int $archivedRevisionCount
	 */
	public function onPageDeleteComplete(
		$page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount
	): void {
		$title = null;
		try {
			$title = Title::castFromPageIdentity( $page );
		} catch ( \Throwable $e ) {
			// fall through to the duck-typed path below
		}
		if ( !$title && is_object( $page ) && method_exists( $page, 'getDBkey' ) ) {
			$title = Title::makeTitleSafe( $page->getNamespace(), $page->getDBkey() );
		}
		self::maybeInvalidateConfigCaches( $title );
	}

	/**
	 * Moving or renaming a config page must not leave a stale cache entry
	 * under either the old or the new title.
	 *
	 * @param mixed $old
	 * @param mixed $new
	 * @param mixed $user
	 * @param int $pageid
	 * @param int $redirid
	 * @param string $reason
	 * @param mixed $revision
	 */
	public function onPageMoveComplete(
		$old, $new, $user, $pageid, $redirid, $reason, $revision
	): void {
		$targets = [
			[ SuggestAccess::getAccessPageTitle(), [ SuggestAccess::class, 'invalidateCache' ] ],
			[ SuggestAccess::getEmailAccessPageTitle(), [ SuggestAccess::class, 'invalidateEmailCache' ] ],
			[ SuggestAccess::getExportAccessPageTitle(), [ SuggestAccess::class, 'invalidateExportCache' ] ],
		];
		foreach ( [ $old, $new ] as $linkTarget ) {
			try {
				$t = Title::newFromLinkTarget( $linkTarget );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( !$t ) {
				continue;
			}
			foreach ( $targets as [ $page, $invalidate ] ) {
				if ( $page && ( $t->equals( $page ) || $t->getPrefixedText() === $page->getPrefixedText() ) ) {
					$invalidate();
				}
			}
			SuggestWikiConfig::maybeInvalidate( $t );
		}
	}

	/**
	 * @param Title|null $title
	 */
	private static function maybeInvalidateConfigCaches( $title ): void {
		if ( !$title ) {
			return;
		}
		$map = [
			[ SuggestAccess::getAccessPageTitle(), [ SuggestAccess::class, 'invalidateCache' ] ],
			[ SuggestAccess::getEmailAccessPageTitle(), [ SuggestAccess::class, 'invalidateEmailCache' ] ],
			[ SuggestAccess::getExportAccessPageTitle(), [ SuggestAccess::class, 'invalidateExportCache' ] ],
		];
		foreach ( $map as [ $page, $invalidate ] ) {
			if ( $page && $title->equals( $page ) ) {
				$invalidate();
			}
		}
		SuggestWikiConfig::maybeInvalidate( $title );
	}
}
