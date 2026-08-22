-- Lists the duplicates folded into one canonical suggestion.
CREATE INDEX /*i*/sps_duplicate_of ON /*_*/sps_suggestion (sg_duplicate_of);
