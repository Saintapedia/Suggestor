<?php
/**
 * Standalone bootstrap for SaintapediaSuggest unit tests.
 *
 * These tests run without a MediaWiki installation: only the pure,
 * service-free helpers are covered here (allow-list parsing, filter
 * normalization, access-page parsing, wiki-config parsing). Anything that
 * touches the database or MediaWikiServices belongs in an integration test
 * run through MediaWiki's own phpunit.php instead.
 *
 * Usage:  composer install && vendor/bin/phpunit
 *     or  phpunit -c phpunit.xml.dist
 */

// Prefer a local composer autoloader when one exists (PHPUnit installed here).
$composer = __DIR__ . '/../../vendor/autoload.php';
if ( file_exists( $composer ) ) {
	require_once $composer;
}

// Minimal PSR-4 autoloader for the extension's own classes, mirroring the
// AutoloadNamespaces entry in extension.json.
spl_autoload_register( static function ( $class ) {
	$prefix = 'MediaWiki\\Extension\\SaintapediaSuggest\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$path = __DIR__ . '/../../includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

// Constants normally provided by MediaWiki core. Declared here so the pure
// helpers can be loaded without pulling in the whole stack.
if ( !defined( 'NS_MEDIAWIKI' ) ) {
	define( 'NS_MEDIAWIKI', 8 );
}
if ( !defined( 'LIST_OR' ) ) {
	define( 'LIST_OR', 4 );
}
if ( !defined( 'DB_PRIMARY' ) ) {
	define( 'DB_PRIMARY', 0 );
}
if ( !defined( 'DB_REPLICA' ) ) {
	define( 'DB_REPLICA', -1 );
}
