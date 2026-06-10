<?php
/**
 * Integration test bootstrap.
 *
 * Loads the real WordPress (with WooCommerce and this plugin active) so tests
 * exercise actual behaviour against the database — no separate test library.
 * Run from an environment where wp-load.php is reachable (the WordPress/CLI
 * container). Override the path with WP_LOAD_PATH if needed.
 *
 * @package Vio\WooSync\Tests
 */

declare( strict_types=1 );

$wp_load = getenv( 'WP_LOAD_PATH' );
if ( ! $wp_load ) {
	$wp_load = '/var/www/html/wp-load.php';
}

if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "vio tests: cannot find wp-load.php at {$wp_load}. Set WP_LOAD_PATH.\n" );
	exit( 1 );
}

// Minimal request context so WordPress boots cleanly in CLI.
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

require $wp_load;

if ( ! class_exists( \Vio\WooSync\Config_Page::class ) ) {
	fwrite( STDERR, "vio tests: plugin not loaded (is Vio WooCommerce Sync active?).\n" );
	exit( 1 );
}
