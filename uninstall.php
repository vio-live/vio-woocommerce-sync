<?php
/**
 * Uninstall cleanup for "Vio Sync for WooCommerce".
 *
 * Runs when the plugin is deleted from the WordPress admin (not on deactivate).
 * Removes the plugin's persistent data — its settings and the per-product sync
 * meta. The Vio order webhooks and the WooCommerce REST keys are already removed
 * by the deactivation hook, which WordPress runs before deleting the plugin.
 *
 * @package VioSync
 */

// Only run in the WordPress uninstall context.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Settings (see Plugin::OPT_* — hard-coded here because the plugin's classes are
// not loaded during uninstall).
delete_option( 'viosync_apikey' );
delete_option( 'viosync_currency' );
delete_option( 'viosync_environment' );

// Per-product sync meta, across every product (see Plugin::META_*).
$viosync_meta_keys = array(
	'vio-product-id',
	'vio-sqs-id',
	'vio-apikey',
	'vio-uid',
	'vio-origin',
);
foreach ( $viosync_meta_keys as $viosync_meta_key ) {
	delete_post_meta_by_key( $viosync_meta_key );
}
