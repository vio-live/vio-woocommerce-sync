<?php
/**
 * Plugin orchestrator: central constants, bootstrap and lifecycle.
 *
 * @package VioSync
 */

declare( strict_types=1 );

namespace VioSync;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const TEXT_DOMAIN = 'vio-sync-for-woocommerce';
	public const LOG_SOURCE   = 'vio-sync-for-woocommerce';

	/** Capability required for every sync operation. */
	public const CAPABILITY = 'manage_woocommerce';

	// --- Database options -------------------------------------------------
	public const OPT_API_KEY     = 'viosync_apikey';
	public const OPT_CURRENCY    = 'viosync_currency';
	public const OPT_ENVIRONMENT = 'viosync_environment';

	// --- Post meta keys ---------------------------------------------------
	public const META_UID        = 'vio-uid';
	public const META_APIKEY     = 'vio-apikey';
	public const META_PRODUCT_ID = 'vio-product-id';
	public const META_SQS_ID     = 'vio-sqs-id';
	public const META_ORIGIN     = 'vio-origin';

	/** Names of the order webhooks managed by Vio. */
	public const WEBHOOK_NAMES = [ 'Vio order.created', 'Vio order.updated' ];

	/** Identifier text for the REST API keys created for Vio. */
	public const API_KEY_DESCRIPTION = 'Vio Sync for WooCommerce';

	/**
	 * Bootstrap: runs on plugins_loaded.
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ self::class, 'woocommerce_missing_notice' ] );
			return;
		}

		// Translations are auto-loaded just-in-time by WordPress.org for hosted
		// plugins (since WP 4.6), so no load_plugin_textdomain() call is needed.

		Config_Page::init();
		Products_Table::init();
		Ajax::init();
	}

	public static function woocommerce_missing_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Vio Sync for WooCommerce requires WooCommerce to be installed and active.', 'vio-sync-for-woocommerce' )
		);
	}

	// --- Lifecycle --------------------------------------------------------

	public static function activate(): void {
		add_option( self::OPT_CURRENCY, '' );
		add_option( self::OPT_ENVIRONMENT, Api_Client::DEFAULT_ENVIRONMENT );
	}

	public static function deactivate(): void {
		update_option( self::OPT_API_KEY, '' );
		update_option( self::OPT_CURRENCY, '' );

		if ( class_exists( 'WooCommerce' ) ) {
			self::cleanup();
		}
	}

	/**
	 * Cleanup on disconnect/deactivate: removes Vio webhooks and REST API keys.
	 */
	public static function cleanup(): void {
		self::remove_webhooks();
		self::remove_api_keys();
	}

	private static function remove_webhooks(): void {
		$data_store   = \WC_Data_Store::load( 'webhook' );
		$backend_host = (string) wp_parse_url( Api_Client::base_url(), PHP_URL_HOST );

		foreach ( $data_store->search_webhooks( array( 'limit' => -1 ) ) as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( ! $webhook ) {
				continue;
			}

			// Match by managed name, or by a delivery URL pointing at the Vio backend.
			$is_vio = in_array( $webhook->get_name(), self::WEBHOOK_NAMES, true );
			if ( ! $is_vio && '' !== $backend_host ) {
				$host   = (string) wp_parse_url( (string) $webhook->get_delivery_url(), PHP_URL_HOST );
				$is_vio = '' !== $host && $host === $backend_host;
			}

			if ( $is_vio ) {
				$webhook->delete( true );
				Logger::info( 'Webhook removed: ' . $webhook->get_name() );
			}
		}
	}

	private static function remove_api_keys(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_api_keys';
		$like  = '%' . $wpdb->esc_like( self::API_KEY_DESCRIPTION ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix . a literal; the LIKE value is prepared. One-off admin cleanup, caching N/A.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT key_id FROM {$table} WHERE description LIKE %s", $like )
		);

		foreach ( $rows as $row ) {
			$wpdb->delete( $table, [ 'key_id' => $row->key_id ], [ '%d' ] );
			Logger::info( 'REST API key removed: ' . $row->key_id );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
