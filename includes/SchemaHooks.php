<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Schema installation and migration.
 *
 * Kept separate from the request-time hooks because LoadExtensionSchemaUpdates
 * runs in the installer/maintenance context, where this extension's services
 * are not available.
 *
 * Fresh installs get every column from tables.sql; the addExtensionField()
 * calls below only fire on wikis that created sps_suggestion before those
 * columns existed. Each guard column is the FIRST column its patch adds, so a
 * patch that is interrupted part-way still re-runs.
 */
class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param \DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';

		$updater->addExtensionTable( 'sps_suggestion', $dir . '/tables.sql' );
		$updater->addExtensionTable( 'sps_suggestion_log', $dir . '/tables.sql' );

		// 0.2.0 — duplicate folding
		$updater->addExtensionField(
			'sps_suggestion',
			'sg_duplicate_of',
			$dir . '/patch-duplicates.sql'
		);

		// 0.2.0 — offline/LLM batch exporter bookkeeping
		$updater->addExtensionField(
			'sps_suggestion',
			'sg_batch_processed',
			$dir . '/patch-batch-processed.sql'
		);

		// 0.3.0 — which row of a multi-row Cargo table a suggestion targets
		$updater->addExtensionField(
			'sps_suggestion',
			'sg_cargo_row_id',
			$dir . '/patch-row-id.sql'
		);

		// Indexes are registered one per patch rather than bundled into the
		// ALTER above. A bare CREATE INDEX aborts the remainder of its patch
		// file when the name already exists — which happens on any wiki where
		// a column was dropped and re-added, because MySQL keeps a composite
		// index alive (minus that column) instead of removing it. Registered
		// individually, each index is guarded by its own existence check.
		$updater->addExtensionIndex(
			'sps_suggestion',
			'sps_dupe_lookup',
			$dir . '/patch-index-dupe-lookup.sql'
		);
		$updater->addExtensionIndex(
			'sps_suggestion',
			'sps_duplicate_of',
			$dir . '/patch-index-duplicate-of.sql'
		);
		$updater->addExtensionIndex(
			'sps_suggestion',
			'sps_batch',
			$dir . '/patch-index-batch.sql'
		);
	}
}
