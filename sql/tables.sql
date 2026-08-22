-- SaintapediaSuggest database schema
--
-- One row per reader-submitted suggestion for a single Cargo field.
-- Collect + triage only: nothing here is ever written back to wikitext
-- or to #cargo_store by this extension.

CREATE TABLE IF NOT EXISTS /*_*/sps_suggestion (
	-- Primary key
	sg_id INT UNSIGNED NOT NULL AUTO_INCREMENT,

	-- The page whose Cargo row this suggestion targets
	sg_page_id INT UNSIGNED NOT NULL,
	sg_page_namespace INT NOT NULL DEFAULT 0,
	sg_page_title VARBINARY(255) NOT NULL,

	-- Cargo target. Table and field are validated against the allow-list
	-- ($wgSaintapediaSuggestTables) at submit time, never trusted from POST.
	sg_cargo_table VARBINARY(200) NOT NULL,
	sg_cargo_field VARBINARY(200) NOT NULL,

	-- Snapshot of the stored value at submit time, so a reviewer can tell
	-- whether the underlying data changed since the reader saw it.
	sg_current_value TEXT NULL DEFAULT NULL,

	-- What the reader proposes instead
	sg_suggested_value TEXT NOT NULL,

	-- Optional free-text rationale
	sg_comment TEXT NULL DEFAULT NULL,

	-- Who submitted (null = anonymous or temp account)
	sg_user_id INT UNSIGNED NULL DEFAULT NULL,
	-- Hashed IP for rate limiting; the raw address is never stored
	sg_ip_hash VARBINARY(64) NOT NULL DEFAULT '',

	-- Optional contact email. Gated behind saintapediasuggest-viewemail;
	-- list/export queries omit this column.
	sg_contact_email VARBINARY(255) NULL DEFAULT NULL,

	-- 'public' or 'enterprise'
	sg_mode VARBINARY(16) NOT NULL DEFAULT 'public',

	-- Workflow status: 'new', 'reviewed', 'actioned', 'dismissed'
	sg_status VARBINARY(16) NOT NULL DEFAULT 'new',

	-- Last status change (denormalized for dashboard display)
	sg_status_user_id INT UNSIGNED NULL DEFAULT NULL,
	sg_status_timestamp VARBINARY(14) NULL DEFAULT NULL,

	-- Private reviewer note (managers only, never shown publicly)
	sg_work_note TEXT NULL DEFAULT NULL,

	-- Deduplication. When several readers report the same wrong value, only
	-- the first row stays canonical (sg_duplicate_of NULL) and carries the
	-- running total in sg_duplicate_count; the rest point at it. The
	-- dashboard lists canonical rows only, so ten reports of one bad phone
	-- number are one queue item rather than ten.
	sg_duplicate_of INT UNSIGNED NULL DEFAULT NULL,
	sg_duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,

	-- Offline/LLM batch export bookkeeping (maintenance/ProcessSuggestions.php)
	sg_batch_processed TINYINT(1) NOT NULL DEFAULT 0,
	sg_batch_timestamp VARBINARY(14) NULL DEFAULT NULL,

	sg_timestamp VARBINARY(14) NOT NULL,

	PRIMARY KEY (sg_id),
	INDEX sps_page_status_time (sg_page_id, sg_status, sg_timestamp),
	INDEX sps_status_time (sg_status, sg_timestamp),
	INDEX sps_ip_time (sg_ip_hash, sg_timestamp),
	INDEX sps_target (sg_cargo_table, sg_cargo_field, sg_status),
	INDEX sps_user (sg_user_id),
	-- Narrows the duplicate search to one page's rows for one field
	INDEX sps_dupe_lookup (sg_page_id, sg_cargo_table, sg_cargo_field, sg_duplicate_of),
	-- Lists the duplicates folded into one canonical row
	INDEX sps_duplicate_of (sg_duplicate_of),
	INDEX sps_batch (sg_batch_processed, sg_timestamp)
) /*$wgDBTableOptions*/;

-- Append-only audit log for status changes
CREATE TABLE IF NOT EXISTS /*_*/sps_suggestion_log (
	slog_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	slog_sg_id INT UNSIGNED NOT NULL,
	slog_user_id INT UNSIGNED NULL DEFAULT NULL,
	slog_old_status VARBINARY(16) NULL DEFAULT NULL,
	slog_new_status VARBINARY(16) NOT NULL,
	slog_note TEXT NULL DEFAULT NULL,
	slog_timestamp VARBINARY(14) NOT NULL,
	PRIMARY KEY (slog_id),
	INDEX sps_log_sg (slog_sg_id, slog_timestamp)
) /*$wgDBTableOptions*/;
