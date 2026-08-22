-- Finds rows the batch exporter has not yet posted.
CREATE INDEX /*i*/sps_batch ON /*_*/sps_suggestion (sg_batch_processed, sg_timestamp);
