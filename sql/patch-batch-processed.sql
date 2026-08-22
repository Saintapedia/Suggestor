-- SaintapediaSuggest: bookkeeping for the offline/LLM batch exporter
-- (maintenance/ProcessSuggestions.php), so a row is posted once and not
-- re-sent on every run.
--
-- Columns only; see patch-duplicates.sql for why the index is separate.

ALTER TABLE /*_*/sps_suggestion
	ADD COLUMN sg_batch_processed TINYINT(1) NOT NULL DEFAULT 0,
	ADD COLUMN sg_batch_timestamp VARBINARY(14) NULL DEFAULT NULL;
