<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Schema installation, kept separate from the request-time hooks because
 * LoadExtensionSchemaUpdates runs in the installer/maintenance context where
 * this extension's services are not available.
 */
class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param \DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';
		$updater->addExtensionTable( 'sps_suggestion', $dir . '/tables.sql' );
		$updater->addExtensionTable( 'sps_suggestion_log', $dir . '/tables.sql' );
	}
}
