-- SaintapediaSuggest: record which row of a Cargo table a suggestion targets.
--
-- A page may store several rows in one table (three sightings on a saint's
-- page, a list of Mass times). Without this, every such suggestion silently
-- referred to whichever row the database returned first, and a reviewer had no
-- way to tell which one the reader meant.
--
-- Existing rows keep NULL: they were all captured against the first row, but
-- claiming that retroactively as an _ID would invent precision that was never
-- recorded.

ALTER TABLE /*_*/sps_suggestion
	ADD COLUMN sg_cargo_row_id INT UNSIGNED NULL DEFAULT NULL,
	ADD COLUMN sg_cargo_row_label VARBINARY(255) NULL DEFAULT NULL;
