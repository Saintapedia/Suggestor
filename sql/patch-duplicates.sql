-- SaintapediaSuggest: fold repeat reports of the same wrong value into one
-- queue item.
--
-- Columns only. The indexes live in their own patch files registered with
-- addExtensionIndex(), because a bare CREATE INDEX aborts the whole patch if
-- the index name already exists, and MediaWiki has no way to resume a
-- half-applied one.

ALTER TABLE /*_*/sps_suggestion
	ADD COLUMN sg_duplicate_of INT UNSIGNED NULL DEFAULT NULL,
	ADD COLUMN sg_duplicate_count INT UNSIGNED NOT NULL DEFAULT 0;
