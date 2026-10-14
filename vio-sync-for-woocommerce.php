<?php
/**
 * Plugin Name:          Vio Sync for WooCommerce
 * Description:          Sync WooCommerce products to the Vio platform (inventory, prices, variants and images).
 * Version:              1.0.2
 * Requires at least:    6.0
 * Requires PHP:         8.0
 * Author:               Vio
 * Author URI:           https://vio.live/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          vio-sync-for-woocommerce
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      10.9
 *
 * @package VioSync
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'VIOSYNC_VERSION', '1.0.2' );
define( 'VIOSYNC_FILE', __FILE__ );
define( 'VIOSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIOSYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'VIOSYNC_BASENAME', plugin_basename( __FILE__ ) );

// Class loading (no Composer: explicit requires in dependency order).
require_once VIOSYNC_DIR . 'includes/class-logger.php';
require_once VIOSYNC_DIR . 'includes/class-api-client.php';
require_once VIOSYNC_DIR . 'includes/class-product-mapper.php';
require_once VIOSYNC_DIR . 'includes/class-sync.php';
require_once VIOSYNC_DIR . 'includes/class-store-status.php';
require_once VIOSYNC_DIR . 'includes/class-config-page.php';
require_once VIOSYNC_DIR . 'includes/class-products-table.php';
require_once VIOSYNC_DIR . 'includes/class-ajax.php';
require_once VIOSYNC_DIR . 'includes/class-plugin.php';

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				VIOSYNC_FILE,
				true
			);
		}
	}
);

// Plugin bootstrap.
add_action( 'plugins_loaded', [ \VioSync\Plugin::class, 'init' ] );

// Lifecycle.
register_activation_hook( __FILE__, [ \VioSync\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \VioSync\Plugin::class, 'deactivate' ] );
