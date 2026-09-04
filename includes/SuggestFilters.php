<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

/**
 * Shared allowlists and filter normalization for the triage dashboard.
 * Deliberately free of MediaWiki services so unit tests exercise it directly.
 */
class SuggestFilters {

	public const VALID_STATUSES = [ 'new', 'reviewed', 'actioned', 'dismissed' ];

	public const VALID_SORTS = [ 'newest', 'oldest' ];

	public const MAX_SEARCH_LENGTH = 100;

	public static function normalizeStatus( ?string $status, string $default = 'new' ): string {
		$status = $status ?? $default;
		if ( $status === 'all' ) {
			return 'all';
		}
		if ( !in_array( $status, self::VALID_STATUSES, true ) ) {
			return $default;
		}
		return $status;
	}

	public static function normalizeSort( ?string $sort ): string {
		$sort = $sort ?? 'newest';
		if ( !in_array( $sort, self::VALID_SORTS, true ) ) {
			return 'newest';
		}
		return $sort;
	}

	/**
	 * Normalize free-text search for LIKE queries.
	 * Returns an empty string if nothing usable remains.
	 *
	 * Does not strip %, _ or \ — Database::buildLike() escapes those in
	 * literal fragments already, and removing them only mangles legitimate
	 * searches such as "50%" or "under_score".
	 */
	public static function sanitizeSearch( ?string $q ): string {
		if ( $q === null ) {
			return '';
		}
		$q = trim( $q );
		if ( $q === '' ) {
			return '';
		}
		if ( mb_strlen( $q ) > self::MAX_SEARCH_LENGTH ) {
			$q = mb_substr( $q, 0, self::MAX_SEARCH_LENGTH );
		}
		return $q;
	}

	/**
	 * A Cargo table/field filter value is only ever used as a bound
	 * parameter, but it is still normalized so an absurd query string does
	 * not reach the database at all.
	 */
	public static function sanitizeIdentifier( ?string $value ): string {
		if ( $value === null ) {
			return '';
		}
		$value = trim( $value );
		if ( $value === '' || mb_strlen( $value ) > 200 ) {
			return '';
		}
		return $value;
	}

	/** @return string[] Allowlisted status actions for process/bulk */
	public static function processActions(): array {
		return [ 'reviewed', 'actioned', 'dismissed' ];
	}

	/**
	 * Build SuggestionStore::updateStatus $opts from a single-item POST.
	 *
	 * workNote is included only when the field was submitted and non-empty,
	 * so a form without the textarea does not NULL an existing note.
	 *
	 * @param string|null $workNote Raw POST value, or null if the field was absent
	 * @return array{workNote?:string}
	 */
	public static function statusUpdateOpts( ?string $workNote ): array {
		$opts = [];
		if ( $workNote !== null && trim( $workNote ) !== '' ) {
			$opts['workNote'] = $workNote;
		}
		return $opts;
	}

	/**
	 * Add a pager offset to a query map when the user is not on page 1.
	 *
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	public static function withOffset( array $query, int $offset ): array {
		if ( $offset > 0 ) {
			$query['offset'] = $offset;
		}
		return $query;
	}

	/**
	 * Snap a pager offset onto a valid page (0 when empty), so ?offset=100
	 * on a 50-row list cannot render "Showing 101–50 of 50".
	 */
	public static function clampOffset( int $offset, int $total, int $limit ): int {
		if ( $limit < 1 || $total <= 0 ) {
			return 0;
		}
		$offset = max( 0, $offset );
		if ( $offset < $total ) {
			return $offset;
		}
		return (int)( floor( ( $total - 1 ) / $limit ) * $limit );
	}

	/**
	 * Decode a per-row dashboard submit value "{id}:{status}".
	 *
	 * A single form wraps every row, so a hidden sps_id per row would all
	 * submit and PHP would keep the last one. Encoding the id on the
	 * clicked button is what makes "Mark actioned" hit the row that was
	 * clicked. Pure; unit-testable.
	 *
	 * @return array{id:int,status:string}|null
	 */
	public static function parseRowAction( ?string $value ): ?array {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$pos = strpos( $value, ':' );
		if ( $pos === false ) {
			return null;
		}
		$idPart = substr( $value, 0, $pos );
		$status = substr( $value, $pos + 1 );
		if ( $idPart === '' || !ctype_digit( $idPart ) ) {
			return null;
		}
		$id = (int)$idPart;
		if ( $id < 1 ) {
			return null;
		}
		if ( !in_array( $status, self::processActions(), true ) ) {
			return null;
		}
		return [ 'id' => $id, 'status' => $status ];
	}
}
