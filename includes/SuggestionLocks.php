<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

/**
 * Named-lock keys for SuggestionStore::tryInsertUnderLimit.
 *
 * Two independent serializations:
 *
 *  - Rate limit is per IP hash. Two readers must not both pass a COUNT
 *    that is still under the cap.
 *  - Duplicate folding is per (page, table, field, row). Two readers
 *    reporting the same value must not both become canonical. That lock
 *    cannot be the rate-limit lock: different IPs would not share it.
 *  - Batch-claim is a single global lock for ProcessSuggestions.php: two
 *    overlapping runs (or a slow one still in flight when the next is
 *    scheduled) must not both select the same pending rows and POST them
 *    twice. One lock for the whole wiki is deliberate -- posting the batch
 *    to an external endpoint isn't a per-target operation the way rate
 *    limiting and duplicate folding are.
 *
 * Names stay under 64 characters (MySQL GET_LOCK's historical limit).
 *
 * Pure; unit-testable.
 */
class SuggestionLocks {

	private const RATE_LIMIT_PREFIX = 'sps-rl-';

	private const DUPLICATE_PREFIX = 'sps-dupe-';

	public const BATCH_CLAIM_LOCK = 'sps-batch-claim';

	/** Hex chars taken from the hash; prefix + this fits in 64. */
	private const HASH_LEN = 40;

	public static function rateLimitLockName( string $ipHash ): string {
		return self::RATE_LIMIT_PREFIX . substr( $ipHash, 0, self::HASH_LEN );
	}

	public static function duplicateLockName(
		int $pageId,
		string $cargoTable,
		string $cargoField,
		?int $cargoRowId
	): string {
		$key = $pageId . "\0" . $cargoTable . "\0" . $cargoField . "\0"
			. ( $cargoRowId === null ? '' : (string)$cargoRowId );
		return self::DUPLICATE_PREFIX . substr( hash( 'sha256', $key ), 0, self::HASH_LEN );
	}
}
