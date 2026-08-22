<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

/**
 * Payload construction and validation for the offline batch exporter
 * (maintenance/ProcessSuggestions.php).
 *
 * Split out of the maintenance script so the rules that matter — what leaves
 * the wiki, and what must never leave it — are unit-testable without a
 * MediaWiki install or a live HTTP endpoint.
 */
class SuggestionBatch {

	public const MAX_BATCH_SIZE = 500;

	public const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Fields sent to the external endpoint.
	 *
	 * The contact email and the IP hash are absent by construction: this
	 * payload crosses a network boundary to a third party, so it carries the
	 * suggestion, not the submitter. The private reviewer note is absent for
	 * the same reason — it is internal triage commentary.
	 */
	public static function buildPayload( array $rows ): array {
		$items = [];
		foreach ( $rows as $row ) {
			if ( !is_object( $row ) ) {
				continue;
			}
			$items[] = [
				'id'             => (int)$row->sg_id,
				'pageId'         => (int)$row->sg_page_id,
				'pageTitle'      => (string)$row->sg_page_title,
				'namespace'      => (int)$row->sg_page_namespace,
				'cargoTable'     => (string)$row->sg_cargo_table,
				'cargoField'     => (string)$row->sg_cargo_field,
				'currentValue'   => isset( $row->sg_current_value )
					? (string)$row->sg_current_value
					: null,
				'suggestedValue' => (string)$row->sg_suggested_value,
				'comment'        => isset( $row->sg_comment ) && $row->sg_comment !== ''
					? (string)$row->sg_comment
					: null,
				'status'         => (string)$row->sg_status,
				'mode'           => (string)$row->sg_mode,
				// How many readers reported the same value, so a classifier
				// can weight corroborated reports.
				'duplicateCount' => (int)( $row->sg_duplicate_count ?? 0 ),
				'timestamp'      => (string)$row->sg_timestamp,
			];
		}

		return [
			'count' => count( $items ),
			'items' => $items,
		];
	}

	/**
	 * Only HTTPS endpoints are accepted: the payload is reader-submitted
	 * content leaving the wiki, and a plaintext POST would expose it (and any
	 * bearer token) on the wire. Pure; unit-testable.
	 */
	public static function isValidWebhook( ?string $url ): bool {
		if ( !is_string( $url ) ) {
			return false;
		}
		$url = trim( $url );
		if ( $url === '' ) {
			return false;
		}
		if ( !filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$scheme = parse_url( $url, PHP_URL_SCHEME );
		return is_string( $scheme ) && strtolower( $scheme ) === 'https';
	}

	/**
	 * Clamp a requested batch size into range. Pure; unit-testable.
	 */
	public static function clampBatchSize( int $requested ): int {
		if ( $requested < 1 ) {
			return self::DEFAULT_BATCH_SIZE;
		}
		return min( $requested, self::MAX_BATCH_SIZE );
	}

	/**
	 * Webhook URL with query string and userinfo stripped, for log output.
	 *
	 * Operators put tokens in query strings even when told not to; printing
	 * the raw URL would copy that secret into logs and terminal scrollback.
	 * Pure; unit-testable.
	 */
	public static function redactUrl( ?string $url ): string {
		if ( !is_string( $url ) || trim( $url ) === '' ) {
			return '(none)';
		}
		$parts = parse_url( trim( $url ) );
		if ( !is_array( $parts ) || !isset( $parts['host'] ) ) {
			return '(invalid URL)';
		}
		$out = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$out .= ':' . $parts['port'];
		}
		$out .= $parts['path'] ?? '';
		if ( isset( $parts['query'] ) ) {
			$out .= '?<redacted>';
		}
		return $out;
	}
}
