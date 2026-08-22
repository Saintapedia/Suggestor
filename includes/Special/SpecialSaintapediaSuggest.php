<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Special;

use Html;
use MediaWiki\Extension\SaintapediaSuggest\SuggestAccess;
use MediaWiki\Extension\SaintapediaSuggest\SuggestFilters;
use MediaWiki\Extension\SaintapediaSuggest\SuggestionStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use PermissionsError;
use SpecialPage;

/**
 * Reviewer dashboard for Cargo field suggestions.
 *
 * - Special:SaintapediaSuggest                  all suggestions, filter/search/bulk
 * - Special:SaintapediaSuggest/<pageid>         one article
 * - Special:SaintapediaSuggest/export           JSON of the current filters
 * - Special:SaintapediaSuggest/export/<pageid>  JSON for one article
 *
 * Triage only. Nothing on this page writes a value back into Cargo or
 * wikitext; "actioned" records that a human made the change elsewhere.
 */
class SpecialSaintapediaSuggest extends SpecialPage {

	private const PAGE_SIZE = 50;

	private const EXPORT_MAX = 5000;

	private SuggestionStore $store;
	private TitleFactory $titleFactory;

	public function __construct( SuggestionStore $store, TitleFactory $titleFactory ) {
		// The restriction is declared for Special:ListGroupRights and
		// LocalSettings; actual access is SuggestAccess (wiki page + defaults
		// + the explicit right), checked in checkPermissions().
		parent::__construct( 'SaintapediaSuggest', 'saintapediasuggest-view' );
		$this->store = $store;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * @param \User $user
	 * @return bool
	 */
	public function userCanExecute( $user ) {
		return SuggestAccess::userCanManage( $user );
	}

	/**
	 * @throws PermissionsError
	 */
	public function checkPermissions() {
		if ( !$this->userCanExecute( $this->getUser() ) ) {
			throw new PermissionsError( 'saintapediasuggest-view' );
		}
	}

	public function doesWrites(): bool {
		return true;
	}

	public function execute( $par ): void {
		$this->setHeaders();
		$out = $this->getOutput();
		$out->addModuleStyles( [ 'mediawiki.special' ] );
		$out->addModules( 'ext.saintapediasuggest.special' );

		$par = (string)( $par ?? '' );

		$this->checkPermissions();

		// Export needs dashboard access plus its own separate right, so a
		// broad triage group does not automatically get bulk offline data.
		if ( $par === 'export' || strpos( $par, 'export/' ) === 0 ) {
			if ( !SuggestAccess::userCanExport( $this->getUser() ) ) {
				throw new PermissionsError( 'saintapediasuggest-export' );
			}
			$this->handleExport( $par );
			return;
		}

		if ( $this->getRequest()->wasPosted() ) {
			$this->checkReadOnly();
			if ( $this->handleStatusUpdate() || $this->handleBulkStatusUpdate() ) {
				return;
			}
		}
		$this->showMutationFlash();

		// Single-suggestion view with its audit trail and folded duplicates.
		if ( strpos( $par, 'detail/' ) === 0 ) {
			$rest = substr( $par, strlen( 'detail/' ) );
			if ( ctype_digit( $rest ) ) {
				$this->showDetail( (int)$rest );
				return;
			}
		}

		if ( $par !== '' && ctype_digit( $par ) ) {
			$this->showPageSuggestions( (int)$par );
			return;
		}

		// Jump to one article's suggestions by title. A dedicated submit name
		// keeps the filter form's "Apply" from triggering this.
		if ( $this->getRequest()->getCheck( 'sps_goto' ) ) {
			if ( $this->handlePageLookup() ) {
				return;
			}
		}

		$this->showDashboard();
	}

	/**
	 * Resolve the title-lookup box to a per-article view.
	 *
	 * @return bool True when a redirect was issued
	 */
	private function handlePageLookup(): bool {
		$pagename = trim( (string)$this->getRequest()->getVal( 'pagename', '' ) );
		if ( $pagename === '' ) {
			return false;
		}

		$title = Title::newFromText( $pagename );
		if ( !$title || !$title->exists() ) {
			$this->getOutput()->addHTML( Html::element(
				'div',
				[ 'class' => 'sps-flash sps-flash-warning' ],
				$this->msg( 'saintapediasuggest-lookup-notfound' )->plaintextParams( $pagename )->text()
			) );
			return false;
		}

		$this->getOutput()->redirect(
			$this->getPageTitle( (string)$title->getArticleID() )->getFullURL()
		);
		return true;
	}

	/* ---------------------------------------------------------------- views */

	private function showDashboard(): void {
		$out = $this->getOutput();
		$filters = $this->getFiltersFromRequest();

		$total = $this->store->countDashboard( $filters );
		$offset = SuggestFilters::clampOffset(
			(int)$this->getRequest()->getInt( 'offset' ),
			$total,
			self::PAGE_SIZE
		);
		$rows = $this->store->getDashboard( $filters, self::PAGE_SIZE, $offset );
		$counts = $this->store->countByStatus( $filters );

		$out->addHTML( Html::rawElement(
			'div',
			[ 'class' => 'sps-intro' ],
			$this->msg( 'saintapediasuggest-dashboard-intro' )->parseAsBlock()
		) );

		$out->addHTML( $this->renderSummaryChips( $counts, $filters ) );
		$out->addHTML( $this->renderPageLookupForm() );
		$out->addHTML( $this->renderFilterForm( $filters ) );

		if ( !$rows ) {
			$out->addHTML( Html::element(
				'p',
				[ 'class' => 'sps-empty' ],
				$this->msg( 'saintapediasuggest-none' )->text()
			) );
			return;
		}

		$out->addHTML( Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle()->getLocalURL( $this->filtersToQuery( $filters ) ),
			'class'  => 'sps-bulk-form',
		] ) );
		$out->addHTML( Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) );
		$out->addHTML( $this->renderBulkToolbar() );

		$out->addHTML( Html::openElement( 'ul', [ 'class' => 'sps-list' ] ) );
		foreach ( $rows as $row ) {
			$out->addHTML( $this->renderRow( $row, true ) );
		}
		$out->addHTML( Html::closeElement( 'ul' ) );
		$out->addHTML( Html::closeElement( 'form' ) );

		$out->addHTML( $this->renderPagination( $filters, $offset, $total, count( $rows ) ) );
		$out->addHTML( $this->renderExportLink( $filters, null ) );
	}

	private function showPageSuggestions( int $pageId ): void {
		$out = $this->getOutput();
		$title = $this->titleFactory->newFromID( $pageId );

		if ( !$title ) {
			$out->addHTML( Html::element(
				'p',
				[ 'class' => 'error' ],
				$this->msg( 'saintapediasuggest-unknown-page' )->numParams( $pageId )->text()
			) );
			return;
		}

		$out->setPageTitle( $this->msg( 'saintapediasuggest-page-title', $title->getPrefixedText() )->text() );
		$out->addHTML( Html::rawElement( 'p', [ 'class' => 'sps-backlinks' ],
			$this->getLinkRenderer()->makeLink(
				$this->getPageTitle(),
				$this->msg( 'saintapediasuggest-back-to-dashboard' )->text()
			)
			. ' · '
			. $this->getLinkRenderer()->makeLink( $title, $title->getPrefixedText() )
		) );

		$filters = $this->getFiltersFromRequest();
		$filters['pageId'] = $pageId;

		$total = $this->store->countDashboard( $filters );
		$offset = SuggestFilters::clampOffset(
			(int)$this->getRequest()->getInt( 'offset' ),
			$total,
			self::PAGE_SIZE
		);
		$rows = $this->store->getDashboard( $filters, self::PAGE_SIZE, $offset );

		$out->addHTML( $this->renderSummaryChips( $this->store->countByStatus( $filters ), $filters ) );

		if ( !$rows ) {
			$out->addHTML( Html::element(
				'p',
				[ 'class' => 'sps-empty' ],
				$this->msg( 'saintapediasuggest-none' )->text()
			) );
			return;
		}

		$out->addHTML( Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle( (string)$pageId )->getLocalURL( $this->filtersToQuery( $filters ) ),
			'class'  => 'sps-bulk-form',
		] ) );
		$out->addHTML( Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) );
		$out->addHTML( Html::hidden( 'sps_pageid', (string)$pageId ) );
		$out->addHTML( $this->renderBulkToolbar() );

		$out->addHTML( Html::openElement( 'ul', [ 'class' => 'sps-list' ] ) );
		foreach ( $rows as $row ) {
			$out->addHTML( $this->renderRow( $row, false ) );
		}
		$out->addHTML( Html::closeElement( 'ul' ) );
		$out->addHTML( Html::closeElement( 'form' ) );

		$out->addHTML( $this->renderPagination( $filters, $offset, $total, count( $rows ) ) );
		$out->addHTML( $this->renderExportLink( $filters, $pageId ) );
	}

	/**
	 * One suggestion in full: its values, every folded duplicate, and the
	 * complete status history. This is the view that answers "who changed
	 * this, when, and why" — the audit table exists for exactly that and is
	 * otherwise invisible.
	 */
	private function showDetail( int $id ): void {
		$out = $this->getOutput();

		$row = $this->store->getById( $id );
		if ( !$row ) {
			$out->addHTML( Html::element(
				'p',
				[ 'class' => 'error' ],
				$this->msg( 'saintapediasuggest-unknown-suggestion' )->numParams( $id )->text()
			) );
			return;
		}

		$out->setPageTitle(
			$this->msg( 'saintapediasuggest-detail-title' )
				->numParams( $id )
				->plaintextParams(
					(string)$row->sg_cargo_table . '.' . (string)$row->sg_cargo_field
				)
				->text()
		);

		$title = $this->titleFactory->newFromID( (int)$row->sg_page_id );
		$links = $this->getLinkRenderer()->makeLink(
			$this->getPageTitle(),
			$this->msg( 'saintapediasuggest-back-to-dashboard' )->text()
		);
		if ( $title ) {
			$links .= ' · ' . $this->getLinkRenderer()->makeLink(
				$this->getPageTitle( (string)$row->sg_page_id ),
				$this->msg( 'saintapediasuggest-page-suggestions-link' )->text()
			);
			$links .= ' · ' . $this->getLinkRenderer()->makeLink( $title, $title->getPrefixedText() );
		}
		$out->addHTML( Html::rawElement( 'p', [ 'class' => 'sps-backlinks' ], $links ) );

		// The row itself, with its action buttons, inside a POST form so a
		// reviewer can act without going back to the list.
		$out->addHTML( Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle( 'detail/' . $id )->getLocalURL(),
			'class'  => 'sps-bulk-form',
		] ) );
		$out->addHTML( Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) );
		// Return here after the mutation instead of dropping back to the list.
		$out->addHTML( Html::hidden( 'sps_detail', (string)$id ) );
		$out->addHTML( Html::openElement( 'ul', [ 'class' => 'sps-list' ] ) );
		$out->addHTML( $this->renderRow( $row, true, false ) );
		$out->addHTML( Html::closeElement( 'ul' ) );
		$out->addHTML( Html::closeElement( 'form' ) );

		$out->addHTML( $this->renderDuplicates( $id ) );
		$out->addHTML( $this->renderAuditLog( $id ) );
	}

	/**
	 * The other readers who reported the same value. Their free text is
	 * shown because a second reporter often supplies the source the first
	 * one omitted.
	 */
	private function renderDuplicates( int $id ): string {
		$duplicates = $this->store->getDuplicates( $id );
		if ( !$duplicates ) {
			return '';
		}

		$lang = $this->getLanguage();
		$user = $this->getUser();

		$items = '';
		foreach ( $duplicates as $dup ) {
			$line = Html::element( 'span', [ 'class' => 'sps-time' ],
				$lang->userTimeAndDate( (string)$dup->sg_timestamp, $user ) );
			$line .= ' ' . Html::element( 'span', [ 'class' => 'sps-dup-value' ],
				(string)$dup->sg_suggested_value );
			if ( (string)( $dup->sg_comment ?? '' ) !== '' ) {
				$line .= Html::element( 'div', [ 'class' => 'sps-comment' ], (string)$dup->sg_comment );
			}
			$items .= Html::rawElement( 'li', [ 'class' => 'sps-dup-item' ], $line );
		}

		return Html::rawElement( 'div', [ 'class' => 'sps-duplicates' ],
			Html::element( 'h3', [],
				$this->msg( 'saintapediasuggest-duplicates-heading' )
					->numParams( count( $duplicates ) )->text() )
			. Html::rawElement( 'ul', [ 'class' => 'sps-dup-list' ], $items )
		);
	}

	/**
	 * Full status history for one suggestion, oldest first.
	 */
	private function renderAuditLog( int $id ): string {
		$entries = $this->store->getStatusLog( $id );

		$heading = Html::element( 'h3', [], $this->msg( 'saintapediasuggest-audit-heading' )->text() );
		if ( !$entries ) {
			return Html::rawElement( 'div', [ 'class' => 'sps-audit' ],
				$heading . Html::element( 'p', [ 'class' => 'sps-empty' ],
					$this->msg( 'saintapediasuggest-audit-empty' )->text() )
			);
		}

		$lang = $this->getLanguage();
		$viewer = $this->getUser();
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		$rows = '';
		foreach ( $entries as $entry ) {
			$actorId = (int)( $entry->slog_user_id ?? 0 );
			$actor = $actorId > 0 ? $userFactory->newFromId( $actorId ) : null;
			$actorCell = $actor && $actor->getName() !== ''
				? $this->getLinkRenderer()->makeLink( $actor->getUserPage(), $actor->getName() )
				: Html::element( 'span', [ 'class' => 'sps-audit-system' ],
					$this->msg( 'saintapediasuggest-audit-unknown-user' )->text() );

			$from = (string)( $entry->slog_old_status ?? '' );
			$transition = ( $from !== ''
					? $this->msg( 'saintapediasuggest-status-' . $from )->text() . ' → '
					: '' )
				. $this->msg( 'saintapediasuggest-status-' . (string)$entry->slog_new_status )->text();

			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [ 'class' => 'sps-audit-time' ],
					$lang->userTimeAndDate( (string)$entry->slog_timestamp, $viewer ) )
				. Html::rawElement( 'td', [ 'class' => 'sps-audit-user' ], $actorCell )
				. Html::element( 'td', [ 'class' => 'sps-audit-change' ], $transition )
				. Html::element( 'td', [ 'class' => 'sps-audit-note' ],
					(string)( $entry->slog_note ?? '' ) )
			);
		}

		$head = Html::rawElement( 'tr', [],
			Html::element( 'th', [], $this->msg( 'saintapediasuggest-audit-when' )->text() )
			. Html::element( 'th', [], $this->msg( 'saintapediasuggest-audit-who' )->text() )
			. Html::element( 'th', [], $this->msg( 'saintapediasuggest-audit-what' )->text() )
			. Html::element( 'th', [], $this->msg( 'saintapediasuggest-audit-note' )->text() )
		);

		return Html::rawElement( 'div', [ 'class' => 'sps-audit' ],
			$heading
			. Html::rawElement( 'table', [ 'class' => 'wikitable sps-audit-table' ],
				Html::rawElement( 'thead', [], $head )
				. Html::rawElement( 'tbody', [], $rows )
			)
		);
	}

	/* ----------------------------------------------------------- mutations */

	/** @return bool True when the request was handled and a redirect issued. */
	private function handleStatusUpdate(): bool {
		$request = $this->getRequest();
		$id = (int)$request->getInt( 'sps_id' );
		$status = (string)$request->getVal( 'sps_status', '' );
		if ( !$id || $status === '' ) {
			return false;
		}

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$this->redirectAfterMutation( [ 'sps_flash' => 'token' ] );
			return true;
		}
		if ( !in_array( $status, SuggestFilters::processActions(), true ) ) {
			$this->redirectAfterMutation( [ 'sps_flash' => 'badstatus' ] );
			return true;
		}

		$pageId = (int)$request->getInt( 'sps_pageid' );
		$ok = $this->store->updateStatus(
			$id,
			$status,
			$pageId > 0 ? $pageId : null,
			$this->getUser()->getId(),
			SuggestFilters::statusUpdateOpts( $request->getVal( 'sps_worknote' ) )
		);

		$this->redirectAfterMutation( [ 'sps_flash' => $ok ? 'updated' : 'notfound' ] );
		return true;
	}

	/** @return bool True when the request was handled and a redirect issued. */
	private function handleBulkStatusUpdate(): bool {
		$request = $this->getRequest();
		$status = (string)$request->getVal( 'sps_bulk_status', '' );
		if ( $status === '' ) {
			return false;
		}

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$this->redirectAfterMutation( [ 'sps_flash' => 'token' ] );
			return true;
		}
		if ( !in_array( $status, SuggestFilters::processActions(), true ) ) {
			$this->redirectAfterMutation( [ 'sps_flash' => 'badstatus' ] );
			return true;
		}

		$ids = $request->getArray( 'sps_ids', [] );
		$ids = array_slice( array_map( 'intval', (array)$ids ), 0, self::PAGE_SIZE );
		$ids = array_values( array_filter( $ids ) );
		if ( !$ids ) {
			$this->redirectAfterMutation( [ 'sps_flash' => 'noselection' ] );
			return true;
		}

		$n = $this->store->updateStatusBulk(
			$ids,
			$status,
			$this->getUser()->getId(),
			$request->getVal( 'sps_bulk_worknote' )
		);

		$this->redirectAfterMutation( [ 'sps_flash' => 'bulk', 'sps_flash_n' => (string)$n ] );
		return true;
	}

	/**
	 * POST/redirect/GET so a browser refresh cannot replay a status change.
	 *
	 * @param array<string,string> $flash
	 */
	private function redirectAfterMutation( array $flash ): void {
		$request = $this->getRequest();
		$pageId = (int)$request->getInt( 'sps_pageid' );
		$query = $this->filtersToQuery( $this->getFiltersFromRequest(), $flash );
		unset( $query['pageid'] );

		$detailId = (int)$request->getInt( 'sps_detail' );
		if ( $detailId > 0 ) {
			$target = $this->getPageTitle( 'detail/' . $detailId );
		} elseif ( $pageId > 0 ) {
			$target = $this->getPageTitle( (string)$pageId );
		} else {
			$target = $this->getPageTitle();
		}

		$this->getOutput()->redirect( $target->getFullURL( $query ) );
	}

	private function showMutationFlash(): void {
		$flash = (string)$this->getRequest()->getVal( 'sps_flash', '' );
		if ( $flash === '' ) {
			return;
		}

		$map = [
			'updated'     => [ 'saintapediasuggest-flash-updated', 'success' ],
			'notfound'    => [ 'saintapediasuggest-flash-notfound', 'error' ],
			'token'       => [ 'saintapediasuggest-flash-token', 'error' ],
			'badstatus'   => [ 'saintapediasuggest-flash-badstatus', 'error' ],
			'noselection' => [ 'saintapediasuggest-flash-noselection', 'warning' ],
			'bulk'        => [ 'saintapediasuggest-flash-bulk', 'success' ],
		];
		if ( !isset( $map[$flash] ) ) {
			return;
		}
		[ $key, $kind ] = $map[$flash];

		$msg = $this->msg( $key );
		if ( $flash === 'bulk' ) {
			$msg->numParams( (int)$this->getRequest()->getInt( 'sps_flash_n' ) );
		}

		$this->getOutput()->addHTML( Html::element(
			'div',
			[ 'class' => 'sps-flash sps-flash-' . $kind ],
			$msg->text()
		) );
	}

	/* -------------------------------------------------------------- export */

	private function handleExport( string $par ): void {
		$filters = $this->getFiltersFromRequest();

		$rest = substr( $par, strlen( 'export' ) );
		$rest = ltrim( $rest, '/' );
		if ( $rest !== '' && ctype_digit( $rest ) ) {
			$filters['pageId'] = (int)$rest;
		}

		$rows = $this->store->getDashboard( $filters, self::EXPORT_MAX, 0 );

		$items = [];
		foreach ( $rows as $row ) {
			// Contact email is deliberately absent: export is a separate
			// right from viewing email, and holding one must not grant the
			// other by way of a download.
			$items[] = [
				'id'             => (int)$row->sg_id,
				'pageId'         => (int)$row->sg_page_id,
				'pageTitle'      => (string)$row->sg_page_title,
				'namespace'      => (int)$row->sg_page_namespace,
				'cargoTable'     => (string)$row->sg_cargo_table,
				'cargoField'     => (string)$row->sg_cargo_field,
				'currentValue'   => $row->sg_current_value !== null ? (string)$row->sg_current_value : null,
				'suggestedValue' => (string)$row->sg_suggested_value,
				'comment'        => $row->sg_comment !== null ? (string)$row->sg_comment : null,
				'status'         => (string)$row->sg_status,
				'mode'           => (string)$row->sg_mode,
				'timestamp'      => (string)$row->sg_timestamp,
			];
		}

		$out = $this->getOutput();
		$out->disable();
		$response = $this->getRequest()->response();
		$response->header( 'Content-Type: application/json; charset=utf-8' );
		$response->header( 'Content-Disposition: attachment; filename="saintapediasuggest-export.json"' );
		$response->header( 'X-Content-Type-Options: nosniff' );
		echo json_encode(
			[ 'count' => count( $items ), 'truncated' => count( $items ) >= self::EXPORT_MAX, 'items' => $items ],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/* ------------------------------------------------------------ rendering */

	/**
	 * @return array<string,mixed>
	 */
	private function getFiltersFromRequest(): array {
		$request = $this->getRequest();

		// The filter form submits one combined "Table.Field" select, while
		// links and bookmarks use separate table= / field= parameters.
		// Accept both, with the explicit parameters winning.
		$table = SuggestFilters::sanitizeIdentifier( $request->getVal( 'table' ) );
		$field = SuggestFilters::sanitizeIdentifier( $request->getVal( 'field' ) );
		if ( $table === '' && $field === '' ) {
			[ $table, $field ] = self::splitTarget( $request->getVal( 'target' ) );
		}

		return [
			'status'     => SuggestFilters::normalizeStatus( $request->getVal( 'status' ), 'new' ),
			'sort'       => SuggestFilters::normalizeSort( $request->getVal( 'sort' ) ),
			'search'     => SuggestFilters::sanitizeSearch( $request->getVal( 'search' ) ),
			'cargoTable' => $table,
			'cargoField' => $field,
			'pageId'     => (int)$request->getInt( 'pageid' ),
		];
	}

	/**
	 * Split a "Table.Field" filter token. Cargo table names cannot contain a
	 * dot, so the first dot is the separator; anything after it is the field.
	 * Pure; safe on empty and malformed input.
	 *
	 * @param string|null $target
	 * @return array{0:string,1:string}
	 */
	public static function splitTarget( ?string $target ): array {
		$target = SuggestFilters::sanitizeIdentifier( $target );
		if ( $target === '' ) {
			return [ '', '' ];
		}
		$pos = strpos( $target, '.' );
		if ( $pos === false ) {
			return [ $target, '' ];
		}
		return [
			SuggestFilters::sanitizeIdentifier( substr( $target, 0, $pos ) ),
			SuggestFilters::sanitizeIdentifier( substr( $target, $pos + 1 ) ),
		];
	}

	/**
	 * @param array<string,mixed> $filters
	 * @param array<string,string> $extra
	 * @return array<string,string>
	 */
	private function filtersToQuery( array $filters, array $extra = [] ): array {
		$query = [];
		if ( !empty( $filters['status'] ) && $filters['status'] !== 'new' ) {
			$query['status'] = (string)$filters['status'];
		}
		if ( !empty( $filters['sort'] ) && $filters['sort'] !== 'newest' ) {
			$query['sort'] = (string)$filters['sort'];
		}
		if ( !empty( $filters['search'] ) ) {
			$query['search'] = (string)$filters['search'];
		}
		if ( !empty( $filters['cargoTable'] ) ) {
			$query['table'] = (string)$filters['cargoTable'];
		}
		if ( !empty( $filters['cargoField'] ) ) {
			$query['field'] = (string)$filters['cargoField'];
		}
		return array_merge( $query, $extra );
	}

	/**
	 * @param array<string,int> $counts
	 * @param array<string,mixed> $filters
	 */
	private function renderSummaryChips( array $counts, array $filters ): string {
		$current = (string)( $filters['status'] ?? 'new' );
		$chips = '';

		foreach ( array_merge( SuggestFilters::VALID_STATUSES, [ 'all' ] ) as $status ) {
			$query = $this->filtersToQuery( array_merge( $filters, [ 'status' => $status ] ) );
			if ( $status === 'new' ) {
				$query['status'] = 'new';
			}
			$target = !empty( $filters['pageId'] )
				? $this->getPageTitle( (string)$filters['pageId'] )
				: $this->getPageTitle();

			$label = $this->msg( 'saintapediasuggest-status-' . $status )->text()
				. ' (' . $this->getLanguage()->formatNum( (int)( $counts[$status] ?? 0 ) ) . ')';

			$chips .= Html::element(
				'a',
				[
					'href'  => $target->getLocalURL( $query ),
					'class' => 'sps-chip' . ( $status === $current ? ' sps-chip-active' : '' ),
				],
				$label
			);
		}

		return Html::rawElement( 'div', [ 'class' => 'sps-chips' ], $chips );
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function renderFilterForm( array $filters ): string {
		$facets = [];
		try {
			$facets = $this->store->getTargetFacets();
		} catch ( \Throwable $e ) {
			// Table may not exist yet; render the form without the facet list.
		}

		$targetOptions = Html::element(
			'option',
			[ 'value' => '' ],
			$this->msg( 'saintapediasuggest-filter-target-all' )->text()
		);
		$currentTarget = trim(
			(string)( $filters['cargoTable'] ?? '' ) . '.' . (string)( $filters['cargoField'] ?? '' ),
			'.'
		);
		foreach ( $facets as $facet ) {
			$value = $facet['table'] . '.' . $facet['field'];
			$attrs = [ 'value' => $value ];
			if ( $value === $currentTarget ) {
				$attrs['selected'] = '';
			}
			$targetOptions .= Html::element(
				'option',
				$attrs,
				$value . ' (' . $this->getLanguage()->formatNum( $facet['count'] ) . ')'
			);
		}

		$sortOptions = '';
		foreach ( SuggestFilters::VALID_SORTS as $sort ) {
			$attrs = [ 'value' => $sort ];
			if ( $sort === ( $filters['sort'] ?? 'newest' ) ) {
				$attrs['selected'] = '';
			}
			$sortOptions .= Html::element(
				'option',
				$attrs,
				$this->msg( 'saintapediasuggest-sort-' . $sort )->text()
			);
		}

		$target = !empty( $filters['pageId'] )
			? $this->getPageTitle( (string)$filters['pageId'] )
			: $this->getPageTitle();

		$inner = Html::hidden( 'status', (string)( $filters['status'] ?? 'new' ) )
			. Html::element( 'label', [ 'for' => 'sps-search' ],
				$this->msg( 'saintapediasuggest-filter-search' )->text() )
			. Html::element( 'input', [
				'type'        => 'search',
				'id'          => 'sps-search',
				'name'        => 'search',
				'value'       => (string)( $filters['search'] ?? '' ),
				'maxlength'   => SuggestFilters::MAX_SEARCH_LENGTH,
				'placeholder' => $this->msg( 'saintapediasuggest-filter-search-placeholder' )->text(),
			] )
			. Html::element( 'label', [ 'for' => 'sps-target' ],
				$this->msg( 'saintapediasuggest-filter-target' )->text() )
			. Html::rawElement( 'select', [ 'id' => 'sps-target', 'name' => 'target' ], $targetOptions )
			. Html::element( 'label', [ 'for' => 'sps-sort' ],
				$this->msg( 'saintapediasuggest-sort' )->text() )
			. Html::rawElement( 'select', [ 'id' => 'sps-sort', 'name' => 'sort' ], $sortOptions )
			. Html::element( 'button', [ 'type' => 'submit' ],
				$this->msg( 'saintapediasuggest-filter-apply' )->text() );

		return Html::rawElement(
			'form',
			[ 'method' => 'get', 'action' => $target->getLocalURL(), 'class' => 'sps-filters' ],
			$inner
		);
	}

	/**
	 * Jump straight to one article's suggestions by title.
	 *
	 * Uses its own submit name (sps_goto) so the filter form's "Apply" cannot
	 * be mistaken for a lookup.
	 */
	private function renderPageLookupForm(): string {
		$inner = Html::element( 'label', [ 'for' => 'sps-pagename' ],
				$this->msg( 'saintapediasuggest-lookup-label' )->text() )
			. Html::element( 'input', [
				'type'        => 'text',
				'id'          => 'sps-pagename',
				'name'        => 'pagename',
				'placeholder' => $this->msg( 'saintapediasuggest-lookup-placeholder' )->text(),
			] )
			. Html::element(
				'button',
				[ 'type' => 'submit', 'name' => 'sps_goto', 'value' => '1' ],
				$this->msg( 'saintapediasuggest-lookup-go' )->text()
			);

		return Html::rawElement(
			'form',
			[
				'method' => 'get',
				'action' => $this->getPageTitle()->getLocalURL(),
				'class'  => 'sps-lookup',
			],
			$inner
		);
	}

	private function renderBulkToolbar(): string {
		$buttons = '';
		foreach ( SuggestFilters::processActions() as $action ) {
			$buttons .= Html::element(
				'button',
				[ 'type' => 'submit', 'name' => 'sps_bulk_status', 'value' => $action, 'class' => 'sps-bulk-btn' ],
				$this->msg( 'saintapediasuggest-bulk-' . $action )->text()
			);
		}

		return Html::rawElement( 'div', [ 'class' => 'sps-bulk-toolbar' ],
			Html::element( 'label', [ 'class' => 'sps-bulk-all' ],
				$this->msg( 'saintapediasuggest-bulk-selectall' )->text() )
			. Html::element( 'input', [
				'type'  => 'checkbox',
				'class' => 'sps-select-all',
				'id'    => 'sps-select-all',
			] )
			. Html::element( 'input', [
				'type'        => 'text',
				'name'        => 'sps_bulk_worknote',
				'class'       => 'sps-bulk-note',
				'maxlength'   => 2000,
				'placeholder' => $this->msg( 'saintapediasuggest-worknote-placeholder' )->text(),
			] )
			. $buttons
		);
	}

	/**
	 * One suggestion. Every value here originates from a reader, so all of it
	 * goes through Html::element / ->text() and never rawElement.
	 */
	private function renderRow( object $row, bool $showPage, bool $linkToDetail = true ): string {
		$id = (int)$row->sg_id;
		$lang = $this->getLanguage();
		$user = $this->getUser();
		$duplicateCount = (int)( $row->sg_duplicate_count ?? 0 );

		$header = Html::element( 'input', [
			'type'  => 'checkbox',
			'name'  => 'sps_ids[]',
			'value' => (string)$id,
			'class' => 'sps-row-check',
		] );

		$header .= Html::element( 'span', [ 'class' => 'sps-target' ],
			(string)$row->sg_cargo_table . '.' . (string)$row->sg_cargo_field );

		$header .= Html::element( 'span', [ 'class' => 'sps-status sps-status-' . (string)$row->sg_status ],
			$this->msg( 'saintapediasuggest-status-' . (string)$row->sg_status )->text() );

		if ( $showPage ) {
			$title = $this->titleFactory->newFromID( (int)$row->sg_page_id );
			if ( $title ) {
				$header .= ' ' . $this->getLinkRenderer()->makeLink( $title, $title->getPrefixedText() );
			} else {
				$header .= ' ' . Html::element( 'span', [ 'class' => 'sps-deleted-page' ],
					$this->msg( 'saintapediasuggest-deleted-page' )->text() );
			}
		}

		// "+3 more readers reported this" — corroboration is the strongest
		// triage signal a reviewer has, so it sits next to the target.
		if ( $duplicateCount > 0 ) {
			$header .= Html::element(
				'span',
				[ 'class' => 'sps-dup-badge' ],
				$this->msg( 'saintapediasuggest-duplicate-badge' )
					->numParams( $duplicateCount )->text()
			);
		}

		$header .= Html::element( 'span', [ 'class' => 'sps-time' ],
			$lang->userTimeAndDate( (string)$row->sg_timestamp, $user ) );

		if ( $linkToDetail ) {
			$header .= ' ' . $this->getLinkRenderer()->makeLink(
				$this->getPageTitle( 'detail/' . $id ),
				$this->msg( 'saintapediasuggest-detail-link' )->text(),
				[ 'class' => 'sps-detail-link' ]
			);
		}

		$body = Html::rawElement( 'div', [ 'class' => 'sps-values' ],
			Html::element( 'div', [ 'class' => 'sps-value sps-value-current' ],
				$this->msg( 'saintapediasuggest-current-label' )->text() . ' '
				. ( (string)( $row->sg_current_value ?? '' ) !== ''
					? (string)$row->sg_current_value
					: $this->msg( 'saintapediasuggest-current-empty' )->text() ) )
			. Html::element( 'div', [ 'class' => 'sps-value sps-value-suggested' ],
				$this->msg( 'saintapediasuggest-suggested-label' )->text() . ' '
				. (string)$row->sg_suggested_value )
		);

		if ( (string)( $row->sg_comment ?? '' ) !== '' ) {
			$body .= Html::element( 'div', [ 'class' => 'sps-comment' ], (string)$row->sg_comment );
		}

		if ( (string)( $row->sg_work_note ?? '' ) !== '' ) {
			$body .= Html::element( 'div', [ 'class' => 'sps-worknote' ],
				$this->msg( 'saintapediasuggest-worknote-label' )->text() . ' ' . (string)$row->sg_work_note );
		}

		if ( SuggestAccess::userCanViewEmail( $user ) ) {
			$emails = $this->store->getContactEmailsById( [ $id ] );
			if ( isset( $emails[$id] ) ) {
				$body .= Html::element( 'div', [ 'class' => 'sps-email' ],
					$this->msg( 'saintapediasuggest-email-label' )->text() . ' ' . $emails[$id] );
			}
		}

		$actions = Html::element( 'input', [
			'type'        => 'text',
			'name'        => 'sps_worknote',
			'class'       => 'sps-row-note',
			'maxlength'   => 2000,
			'placeholder' => $this->msg( 'saintapediasuggest-worknote-placeholder' )->text(),
		] );
		foreach ( SuggestFilters::processActions() as $action ) {
			$actions .= Html::element(
				'button',
				[
					'type'  => 'submit',
					'name'  => 'sps_status',
					'value' => $action,
					'class' => 'sps-action sps-action-' . $action,
				],
				$this->msg( 'saintapediasuggest-action-' . $action )->text()
			);
		}
		$actions = Html::hidden( 'sps_id', (string)$id ) . $actions;

		return Html::rawElement( 'li', [ 'class' => 'sps-item', 'data-sps-id' => (string)$id ],
			Html::rawElement( 'div', [ 'class' => 'sps-item-header' ], $header )
			. $body
			. Html::rawElement( 'div', [ 'class' => 'sps-item-actions' ], $actions )
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function renderPagination( array $filters, int $offset, int $total, int $shown ): string {
		if ( $total <= self::PAGE_SIZE ) {
			return '';
		}

		$target = !empty( $filters['pageId'] )
			? $this->getPageTitle( (string)$filters['pageId'] )
			: $this->getPageTitle();
		$lang = $this->getLanguage();

		$links = '';
		if ( $offset > 0 ) {
			$prev = max( 0, $offset - self::PAGE_SIZE );
			$links .= Html::element( 'a', [
				'href'  => $target->getLocalURL(
					SuggestFilters::withOffset( $this->filtersToQuery( $filters ), $prev )
				),
				'class' => 'sps-pager-prev',
			], $this->msg( 'saintapediasuggest-pager-prev' )->text() );
		}
		if ( $offset + $shown < $total ) {
			$links .= Html::element( 'a', [
				'href'  => $target->getLocalURL(
					SuggestFilters::withOffset( $this->filtersToQuery( $filters ), $offset + self::PAGE_SIZE )
				),
				'class' => 'sps-pager-next',
			], $this->msg( 'saintapediasuggest-pager-next' )->text() );
		}

		$summary = $this->msg( 'saintapediasuggest-pager-summary' )
			->params(
				$lang->formatNum( $offset + 1 ),
				$lang->formatNum( $offset + $shown ),
				$lang->formatNum( $total )
			)->text();

		return Html::rawElement( 'div', [ 'class' => 'sps-pager' ],
			Html::element( 'span', [ 'class' => 'sps-pager-summary' ], $summary ) . $links );
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function renderExportLink( array $filters, ?int $pageId ): string {
		if ( !SuggestAccess::userCanExport( $this->getUser() ) ) {
			return '';
		}
		$sub = $pageId ? 'export/' . $pageId : 'export';
		return Html::rawElement( 'p', [ 'class' => 'sps-export' ],
			Html::element(
				'a',
				[ 'href' => $this->getPageTitle( $sub )->getLocalURL( $this->filtersToQuery( $filters ) ) ],
				$this->msg( 'saintapediasuggest-export' )->text()
			)
		);
	}

	protected function getGroupName(): string {
		return 'wiki';
	}
}
