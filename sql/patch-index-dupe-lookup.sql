-- Narrows the duplicate search to one page's rows for one Cargo field.
CREATE INDEX /*i*/sps_dupe_lookup
	ON /*_*/sps_suggestion (sg_page_id, sg_cargo_table, sg_cargo_field, sg_duplicate_of);
