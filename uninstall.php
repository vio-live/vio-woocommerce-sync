<?php
/**
 * Uninstall cleanup for "Vio Sync for WooCommerce".
 *
 * Runs when the plugin is deleted from the WordPress admin (not on deactivate).
 * Removes the plugin's persistent data — its settings and the per-product sync
 * meta. The Vio order webhooks and the WooCommerce REST keys are already removed
 * by the deactivation hook, which WordPress runs before deleting the plugin.
 *
 * @package Vio\WooSync
 */

// Only run in the WordPress uninstall context.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Settings (see Plugin::OPT_* — hard-coded here because the plugin's classes are
// not loaded during uninstall).
delete_option( 'vio_apikey' );
delete_option( 'vio_currency' );
delete_option( 'vio_environment' );

// Per-product sync meta, across every product (see Plugin::META_*).
$vio_meta_keys = array(
	'vio-product-id',
	'vio-sqs-id',
	'vio-apikey',
	'vio-uid',
	'vio-origin',
);
foreach ( $vio_meta_keys as $vio_meta_key ) {
	delete_post_meta_by_key( $vio_meta_key );
}
