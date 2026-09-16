<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * All database access for suggestions. Request handlers (API module, special
 * page) call this and never touch the load balancer themselves.
 *
 * Nothing here writes to Cargo or to wikitext — this version is collect and
 * triage only, so the only mutation a reviewer can make is a status change
 * plus a private work note.
 */
class SuggestionStore {

	/**
	 * Columns loaded for dashboard / per-page lists.
	 * Omits the contact email and IP hash so list queries never materialize
	 * PII; email is fetched separately, only for rows being displayed, and
	 * only for a user holding saintapediasuggest-viewemail.
	 */
	public const MANAGER_LIST_FIELDS = [
		'sg_id',
		'sg_page_id',
		'sg_page_namespace',
		'sg_page_title',
		'sg_cargo_table',
		'sg_cargo_field',
		'sg_cargo_row_id',
		'sg_cargo_row_label',
		'sg_current_value',
		'sg_suggested_value',
		'sg_comment',
		'sg_mode',
		'sg_status',
		'sg_status_user_id',
		'sg_status_timestamp',
		'sg_work_note',
		'sg_duplicate_of',
		'sg_duplicate_count',
		'sg_timestamp',
	];

	/** Upper bound on open variants scanned when looking for a duplicate. */
	private const DUPLICATE_SCAN_CAP = 200;

	private ILoadBalancer $loadBalancer;

	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	public function insert( array $data ): int {
		return $this->insertOn( $this->loadBalancer->getConnection( DB_PRIMARY ), $data );
	}

	/**
	 * Insert only if this IP hash is under the 24h cap.
	 *
	 * Serializes same-hash submits with a named lock so concurrent COUNTs
	 * cannot all pass the check at once (including the first-row case where
	 * the counted range is still empty). Duplicate folding uses a second
	 * lock keyed on the target, because two different IPs would not share
	 * the rate-limit lock.
	 *
	 * @return int|null New id, or null when over the limit / lock unavailable
	 */
	public function tryInsertUnderLimit( array $data, int $limit ): ?int {
		$ipHash = (string)( $data['ipHash'] ?? '' );
		if ( $ipHash === '' || $limit < 1 ) {
			return null;
		}
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$ipLock = SuggestionLocks::rateLimitLockName( $ipHash );
		if ( !$db->lock( $ipLock, __METHOD__, 3 ) ) {
			return null;
		}
		try {
			if ( $this->countRecentByIpHash( $ipHash, $db ) >= $limit ) {
				return null;
			}

			// Fold this into an existing open suggestion when another reader
			// already proposed the same value for the same field. The
			// rate-limit lock is per IP, so two different readers reporting
			// the same problem would not share it — a second lock keyed on
			// the target is what stops both inserts becoming canonical.
			$canonical = null;
			$dupeLock = null;
			$merge = $data['mergeDuplicates'] ?? true;
			if ( $merge ) {
				$dupeLock = SuggestionLocks::duplicateLockName(
					(int)$data['pageId'],
					(string)$data['cargoTable'],
					(string)$data['cargoField'],
					isset( $data['cargoRowId'] ) && $data['cargoRowId'] !== null
						? (int)$data['cargoRowId']
						: null
				);
				if ( !$db->lock( $dupeLock, __METHOD__, 3 ) ) {
					return null;
				}
			}
			try {
				if ( $merge ) {
					$canonical = $this->findOpenDuplicate(
						(int)$data['pageId'],
						(string)$data['cargoTable'],
						(string)$data['cargoField'],
						(string)$data['suggestedValue'],
						$data['cargoRowId'] ?? null,
						$db
					);
				}
				$data['duplicateOf'] = $canonical;

				$id = $this->insertOn( $db, $data );

				if ( $canonical !== null ) {
					$this->bumpDuplicateCount( $db, $canonical );
				}
				return $id;
			} finally {
				if ( $dupeLock !== null ) {
					$db->unlock( $dupeLock, __METHOD__ );
				}
			}
		} finally {
			$db->unlock( $ipLock, __METHOD__ );
		}
	}

	/**
	 * Canonical suggestion this submission duplicates, if any.
	 *
	 * Narrows to the same page/table/field in SQL (the sps_dupe_lookup
	 * index), then applies SuggestionMerger's normalized comparison in PHP —
	 * the match folds case, whitespace and Unicode punctuation, which the
	 * database cannot express without storing a second redundant column.
	 *
	 * @param IDatabase|null $db Primary connection when called under the lock
	 */
	public function findOpenDuplicate(
		int $pageId,
		string $cargoTable,
		string $cargoField,
		string $suggestedValue,
		?int $cargoRowId = null,
		?IDatabase $db = null
	): ?int {
		if ( $pageId <= 0 || SuggestionMerger::normalizeValue( $suggestedValue ) === '' ) {
			return null;
		}
		$db ??= $this->loadBalancer->getConnection( DB_REPLICA );

		$conds = [
			'sg_page_id'      => $pageId,
			'sg_cargo_table'  => $cargoTable,
			'sg_cargo_field'  => $cargoField,
			'sg_status'       => SuggestionMerger::OPEN_STATUSES,
			'sg_duplicate_of' => null,
		];
		// Two readers correcting *different* rows to the same value are not
		// reporting the same problem, so row identity is part of the key.
		// Legacy rows (NULL row id) only ever match other legacy rows.
		$conds['sg_cargo_row_id'] = $cargoRowId;

		$rows = $db->select(
			'sps_suggestion',
			[ 'sg_id', 'sg_status', 'sg_suggested_value', 'sg_duplicate_of' ],
			$conds,
			__METHOD__,
			[
				'ORDER BY' => 'sg_id ASC',
				// A field with more open variants than this is already a
				// triage problem; scanning further would not help.
				'LIMIT' => self::DUPLICATE_SCAN_CAP,
			]
		);

		return SuggestionMerger::pickCanonical( iterator_to_array( $rows ), $suggestedValue );
	}

	private function bumpDuplicateCount( IDatabase $db, int $canonicalId ): void {
		$db->update(
			'sps_suggestion',
			[ 'sg_duplicate_count = sg_duplicate_count + 1' ],
			[ 'sg_id' => $canonicalId ],
			__METHOD__
		);
	}

	/**
	 * The duplicate rows folded into one canonical suggestion, oldest first.
	 *
	 * @return object[]
	 */
	public function getDuplicates( int $canonicalId, int $limit = 50 ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$rows = $db->select(
			'sps_suggestion',
			[ 'sg_id', 'sg_suggested_value', 'sg_comment', 'sg_mode', 'sg_timestamp' ],
			[ 'sg_duplicate_of' => $canonicalId ],
			__METHOD__,
			[ 'ORDER BY' => 'sg_timestamp ASC, sg_id ASC', 'LIMIT' => $limit ]
		);
		return iterator_to_array( $rows );
	}

	private function insertOn( IDatabase $db, array $data ): int {
		$db->insert(
			'sps_suggestion',
			[
				'sg_page_id'         => $data['pageId'],
				'sg_page_namespace'  => $data['namespace'],
				'sg_page_title'      => $data['title'],
				'sg_cargo_table'     => $data['cargoTable'],
				'sg_cargo_field'     => $data['cargoField'],
				'sg_cargo_row_id'    => $data['cargoRowId'] ?? null,
				'sg_cargo_row_label' => $data['cargoRowLabel'] ?? null,
				'sg_current_value'   => $data['currentValue'] ?? null,
				'sg_suggested_value' => $data['suggestedValue'],
				'sg_comment'         => $data['comment'] ?? null,
				'sg_user_id'         => $data['userId'] ?? null,
				'sg_ip_hash'         => $data['ipHash'],
				'sg_contact_email'   => $data['contactEmail'] ?? null,
				'sg_mode'            => $data['mode'],
				'sg_status'          => 'new',
				'sg_duplicate_of'    => $data['duplicateOf'] ?? null,
				'sg_timestamp'       => $db->timestamp(),
			],
			__METHOD__
		);
		return (int)$db->insertId();
	}

	/**
	 * Count submissions from a given IP hash within the past 24 hours.
	 *
	 * @param IDatabase|null $db Primary connection when called under tryInsertUnderLimit
	 */
	public function countRecentByIpHash( string $ipHash, ?IDatabase $db = null ): int {
		$db ??= $this->loadBalancer->getConnection( DB_PRIMARY );
		$cutoff = $db->timestamp( time() - 86400 );
		return (int)$db->selectField(
			'sps_suggestion',
			'COUNT(*)',
			[
				'sg_ip_hash' => $ipHash,
				'sg_timestamp > ' . $db->addQuotes( $cutoff ),
			],
			__METHOD__
		);
	}

	/**
	 * Counts for one page, for the toolbox badge.
	 *
	 * @return array{open:int,resolved:int,total:int,new:int}
	 */
	public function getPageCounts( int $pageId ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $db->select(
			'sps_suggestion',
			[ 'sg_status', 'cnt' => 'COUNT(*)' ],
			// Canonical rows only, so the toolbox badge matches what the
			// dashboard will actually show.
			[ 'sg_page_id' => $pageId, 'sg_duplicate_of' => null ],
			__METHOD__,
			[ 'GROUP BY' => 'sg_status' ]
		);
		$by = [ 'new' => 0, 'reviewed' => 0, 'actioned' => 0, 'dismissed' => 0 ];
		foreach ( $res as $row ) {
			$by[(string)$row->sg_status] = (int)$row->cnt;
		}
		return [
			'open'     => $by['new'] + $by['reviewed'],
			'resolved' => $by['actioned'],
			'total'    => array_sum( $by ),
			'new'      => $by['new'],
		];
	}

	/**
	 * Contact emails for the given ids only. Never part of list/export
	 * SELECTs — the caller must have checked saintapediasuggest-viewemail.
	 *
	 * @param int[] $ids
	 * @return array<int,string>
	 */
	public function getContactEmailsById( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( !$ids ) {
			return [];
		}
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $db->select(
			'sps_suggestion',
			[ 'sg_id', 'sg_contact_email' ],
			[ 'sg_id' => $ids ],
			__METHOD__
		);
		$out = [];
		foreach ( $res as $row ) {
			$email = trim( (string)( $row->sg_contact_email ?? '' ) );
			if ( $email !== '' ) {
				$out[(int)$row->sg_id] = $email;
			}
		}
		return $out;
	}

	/**
	 * Dashboard listing with filters and sort.
	 *
	 * @param array $filters Keys: status, pageId, cargoTable, cargoField, sort, search
	 * @return object[]
	 */
	public function getDashboard( array $filters, int $limit = 50, int $offset = 0 ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		[ $conds, $options ] = $this->buildDashboardQuery( $db, $filters, $limit, $offset );
		$rows = $db->select( 'sps_suggestion', self::MANAGER_LIST_FIELDS, $conds, __METHOD__, $options );
		return iterator_to_array( $rows );
	}

	/** Total rows matching dashboard filters (for pagination). */
	public function countDashboard( array $filters ): int {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		[ $conds ] = $this->buildDashboardQuery( $db, $filters, null, null );
		return (int)$db->selectField( 'sps_suggestion', 'COUNT(*)', $conds, __METHOD__ );
	}

	/**
	 * Counts keyed by status for the filter chips. Respects every filter
	 * except status itself, so the chips show what switching would yield.
	 *
	 * @return array<string,int>
	 */
	public function countByStatus( array $filters = [] ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$filtersForSummary = $filters;
		unset( $filtersForSummary['status'] );
		[ $conds ] = $this->buildDashboardQuery( $db, $filtersForSummary, null, null );

		$res = $db->select(
			'sps_suggestion',
			[ 'sg_status', 'cnt' => 'COUNT(*)' ],
			$conds,
			__METHOD__,
			[ 'GROUP BY' => 'sg_status' ]
		);

		$counts = [ 'new' => 0, 'reviewed' => 0, 'actioned' => 0, 'dismissed' => 0 ];
		foreach ( $res as $row ) {
			$counts[(string)$row->sg_status] = (int)$row->cnt;
		}
		$counts['all'] = array_sum( $counts );
		return $counts;
	}

	/**
	 * Distinct (table, field) pairs that actually have suggestions, for the
	 * dashboard's target filter dropdown.
	 *
	 * @return list<array{table:string,field:string,count:int}>
	 */
	public function getTargetFacets( int $limit = 100 ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $db->select(
			'sps_suggestion',
			[ 'sg_cargo_table', 'sg_cargo_field', 'cnt' => 'COUNT(*)' ],
			[ 'sg_duplicate_of' => null ],
			__METHOD__,
			[
				'GROUP BY' => [ 'sg_cargo_table', 'sg_cargo_field' ],
				'ORDER BY' => 'cnt DESC',
				'LIMIT'    => $limit,
			]
		);
		$out = [];
		foreach ( $res as $row ) {
			$out[] = [
				'table' => (string)$row->sg_cargo_table,
				'field' => (string)$row->sg_cargo_field,
				'count' => (int)$row->cnt,
			];
		}
		return $out;
	}

	/**
	 * @param IDatabase $db
	 * @param array $filters
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return array{0:array,1:array}
	 */
	private function buildDashboardQuery( $db, array $filters, ?int $limit, ?int $offset ): array {
		$conds = [];

		// Folded duplicates are never listed on their own: they belong to the
		// canonical row, which shows them as a count. Pass
		// includeDuplicates=true only where every raw submission is wanted
		// (the batch exporter, and the canonical row's detail view).
		if ( empty( $filters['includeDuplicates'] ) ) {
			$conds['sg_duplicate_of'] = null;
		}

		$status = $filters['status'] ?? null;
		if ( is_string( $status ) && $status !== '' && $status !== 'all' ) {
			$conds['sg_status'] = $status;
		}

		$pageId = $filters['pageId'] ?? null;
		if ( $pageId ) {
			$conds['sg_page_id'] = (int)$pageId;
		}

		$table = SuggestFilters::sanitizeIdentifier( $filters['cargoTable'] ?? null );
		if ( $table !== '' ) {
			$conds['sg_cargo_table'] = $table;
		}

		$field = SuggestFilters::sanitizeIdentifier( $filters['cargoField'] ?? null );
		if ( $field !== '' ) {
			$conds['sg_cargo_field'] = $field;
		}

		$search = SuggestFilters::sanitizeSearch( $filters['search'] ?? null );
		if ( $search !== '' ) {
			$like = $db->buildLike( $db->anyString(), $search, $db->anyString() );
			$conds[] = $db->makeList( [
				'sg_suggested_value ' . $like,
				'sg_current_value ' . $like,
				'sg_comment ' . $like,
				'sg_page_title ' . $like,
				'sg_cargo_field ' . $like,
			], LIST_OR );
		}

		$sort = ( $filters['sort'] ?? 'newest' ) === 'oldest' ? 'ASC' : 'DESC';
		$options = [ 'ORDER BY' => "sg_timestamp $sort, sg_id $sort" ];
		if ( $limit !== null ) {
			$options['LIMIT'] = $limit;
		}
		if ( $offset !== null ) {
			$options['OFFSET'] = $offset;
		}

		return [ $conds, $options ];
	}

	/**
	 * Hold the batch-claim lock for the duration of a full
	 * getPendingBatch() -> POST -> markBatchProcessed() sequence in
	 * ProcessSuggestions.php, so an overlapping run (or a slow one still in
	 * flight) cannot select and repost the same rows. Callers must release
	 * via releaseBatchLock() in a finally block regardless of outcome —
	 * MySQL also releases GET_LOCK automatically if the process dies before
	 * that, but an explicit release keeps a pooled/reused connection from
	 * holding it longer than necessary.
	 *
	 * @return bool False when the lock is already held elsewhere
	 */
	public function acquireBatchLock( int $timeout = 3 ): bool {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		return $db->lock( SuggestionLocks::BATCH_CLAIM_LOCK, __METHOD__, $timeout );
	}

	public function releaseBatchLock(): void {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$db->unlock( SuggestionLocks::BATCH_CLAIM_LOCK, __METHOD__ );
	}

	/**
	 * Canonical suggestions not yet posted to the batch webhook, oldest first.
	 *
	 * Only open items are exported: an actioned or dismissed suggestion has
	 * already had a human decision and does not need offline triage help.
	 *
	 * Reads DB_PRIMARY, not a replica: called only under acquireBatchLock(),
	 * where the point is to see every row markBatchProcessed() has already
	 * committed, not a possibly-lagged replica view of the same rows.
	 *
	 * @return object[]
	 */
	public function getPendingBatch( int $limit = 100 ): array {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$rows = $db->select(
			'sps_suggestion',
			array_merge( self::MANAGER_LIST_FIELDS, [ 'sg_batch_processed' ] ),
			[
				'sg_batch_processed' => 0,
				'sg_status'          => SuggestionMerger::OPEN_STATUSES,
				'sg_duplicate_of'    => null,
			],
			__METHOD__,
			[ 'ORDER BY' => 'sg_timestamp ASC, sg_id ASC', 'LIMIT' => $limit ]
		);
		return iterator_to_array( $rows );
	}

	/**
	 * Mark rows as exported. Called only after the webhook accepted them, so
	 * a failed POST is retried on the next run rather than silently dropped.
	 *
	 * @param int[] $ids
	 */
	public function markBatchProcessed( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( !$ids ) {
			return 0;
		}
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$db->update(
			'sps_suggestion',
			[
				'sg_batch_processed' => 1,
				'sg_batch_timestamp' => $db->timestamp(),
			],
			[ 'sg_id' => $ids ],
			__METHOD__
		);
		return (int)$db->affectedRows();
	}

	/** One row by id, including the columns list queries omit. */
	public function getById( int $id ): ?object {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$row = $db->selectRow( 'sps_suggestion', self::MANAGER_LIST_FIELDS, [ 'sg_id' => $id ], __METHOD__ );
		return $row ?: null;
	}

	/**
	 * Update workflow status for one suggestion and append an audit entry.
	 *
	 * When $pageId is given, the row must belong to that page — this stops
	 * the per-page view from mutating a suggestion on another article via a
	 * forged id.
	 *
	 * @param array $opts Optional key: workNote (string) private reviewer note
	 * @return bool True if a matching row was updated
	 */
	public function updateStatus(
		int $id,
		string $status,
		?int $pageId = null,
		?int $actorUserId = null,
		array $opts = []
	): bool {
		if ( !in_array( $status, SuggestFilters::VALID_STATUSES, true ) ) {
			return false;
		}

		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$conds = [ 'sg_id' => $id ];
		if ( $pageId !== null ) {
			$conds['sg_page_id'] = $pageId;
		}

		$row = $db->selectRow( 'sps_suggestion', [ 'sg_id', 'sg_status' ], $conds, __METHOD__ );
		if ( !$row ) {
			return false;
		}
		$old = (string)$row->sg_status;

		$workNote = array_key_exists( 'workNote', $opts ) ? $this->clampNote( $opts['workNote'] ) : null;

		$set = [
			'sg_status'           => $status,
			'sg_status_user_id'   => $actorUserId,
			'sg_status_timestamp' => $db->timestamp(),
		];
		if ( array_key_exists( 'workNote', $opts ) ) {
			$set['sg_work_note'] = $workNote;
		}

		$ts = $set['sg_status_timestamp'];
		$db->update( 'sps_suggestion', $set, $conds, __METHOD__ );

		if ( $old !== $status || $workNote !== null ) {
			$this->insertStatusLog( $db, $id, $actorUserId, $old, $status, $ts, $workNote );
		}
		return true;
	}

	/**
	 * Bulk-update workflow status for many ids, with the same audit trail.
	 *
	 * @param int[] $ids
	 * @return int Number of rows updated
	 */
	public function updateStatusBulk(
		array $ids,
		string $status,
		?int $actorUserId = null,
		?string $workNote = null,
		?int $pageId = null
	): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( !$ids || !in_array( $status, SuggestFilters::processActions(), true ) ) {
			return 0;
		}

		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$note = $workNote !== null ? $this->clampNote( $workNote ) : null;

		// Read prior statuses first so the audit log records real transitions
		// rather than "unknown -> actioned" for every row. $pageId, when
		// given, scopes this the same way updateStatus() scopes a single-row
		// update: the per-article view's bulk button must not be able to
		// mutate a row belonging to a different page via a forged id.
		$conds = [ 'sg_id' => $ids ];
		if ( $pageId !== null ) {
			$conds['sg_page_id'] = $pageId;
		}
		$prior = [];
		$res = $db->select( 'sps_suggestion', [ 'sg_id', 'sg_status' ], $conds, __METHOD__ );
		foreach ( $res as $row ) {
			$prior[(int)$row->sg_id] = (string)$row->sg_status;
		}
		if ( !$prior ) {
			return 0;
		}

		$set = [
			'sg_status'           => $status,
			'sg_status_user_id'   => $actorUserId,
			'sg_status_timestamp' => $db->timestamp(),
		];
		if ( $note !== null ) {
			$set['sg_work_note'] = $note;
		}
		$ts = $set['sg_status_timestamp'];

		$db->update( 'sps_suggestion', $set, [ 'sg_id' => array_keys( $prior ) ], __METHOD__ );
		$affected = $db->affectedRows();

		foreach ( $prior as $id => $old ) {
			if ( $old !== $status || $note !== null ) {
				$this->insertStatusLog( $db, $id, $actorUserId, $old, $status, $ts, $note );
			}
		}

		return (int)$affected;
	}

	/**
	 * Audit entries for one suggestion, oldest first.
	 *
	 * @return object[]
	 */
	public function getStatusLog( int $id, int $limit = 50 ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$rows = $db->select(
			'sps_suggestion_log',
			[ 'slog_id', 'slog_user_id', 'slog_old_status', 'slog_new_status', 'slog_note', 'slog_timestamp' ],
			[ 'slog_sg_id' => $id ],
			__METHOD__,
			[ 'ORDER BY' => 'slog_timestamp ASC, slog_id ASC', 'LIMIT' => $limit ]
		);
		return iterator_to_array( $rows );
	}

	private function insertStatusLog(
		IDatabase $db,
		int $id,
		?int $actorUserId,
		?string $oldStatus,
		string $newStatus,
		string $timestamp,
		?string $note
	): void {
		$db->insert(
			'sps_suggestion_log',
			[
				'slog_sg_id'      => $id,
				'slog_user_id'    => $actorUserId,
				'slog_old_status' => $oldStatus,
				'slog_new_status' => $newStatus,
				'slog_note'       => $note,
				'slog_timestamp'  => $timestamp,
			],
			__METHOD__
		);
	}

	/**
	 * @param mixed $note
	 */
	private function clampNote( $note, int $max = 2000 ): ?string {
		if ( !is_string( $note ) ) {
			return null;
		}
		$note = trim( $note );
		if ( $note === '' ) {
			return null;
		}
		if ( mb_strlen( $note ) > $max ) {
			$note = mb_substr( $note, 0, $max );
		}
		return $note;
	}
}
