<?php
/**
 * Plugin orchestrator: central constants, bootstrap and lifecycle.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const TEXT_DOMAIN = 'vio-woocommerce-sync';
	public const LOG_SOURCE   = 'vio-woocommerce-sync';

	/** Capability required for every sync operation. */
	public const CAPABILITY = 'manage_woocommerce';

	// --- Database options -------------------------------------------------
	public const OPT_API_KEY     = 'vio_apikey';
	public const OPT_CURRENCY    = 'vio_currency';
	public const OPT_ENVIRONMENT = 'vio_environment';

	// --- Post meta keys ---------------------------------------------------
	public const META_UID        = 'vio-uid';
	public const META_APIKEY     = 'vio-apikey';
	public const META_PRODUCT_ID = 'vio-product-id';
	public const META_SQS_ID     = 'vio-sqs-id';
	public const META_ORIGIN     = 'vio-origin';

	/**
	 * Legacy Reachu meta key. After creating a product the backend writes its
	 * Vio id back into the WooCommerce post under this key (the old plugin's
	 * name). The plugin reads it and backfills META_PRODUCT_ID — see
	 * Store_Status::reconcile_remote_ids().
	 */
	public const META_LEGACY_PRODUCT_ID = 'reachu-product-id';

	/** Names of the order webhooks managed by Vio. */
	public const WEBHOOK_NAMES = [ 'Vio order.created', 'Vio order.updated' ];

	/** Identifier text for the REST API keys created for Vio. */
	public const API_KEY_DESCRIPTION = 'Vio WooCommerce Sync';

	/**
	 * Bootstrap: runs on plugins_loaded.
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ self::class, 'woocommerce_missing_notice' ] );
			return;
		}

		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( VIO_WC_SYNC_BASENAME ) . '/languages'
		);

		Config_Page::init();
		Products_Table::init();
		Ajax::init();
	}

	public static function woocommerce_missing_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Vio WooCommerce Sync requires WooCommerce to be installed and active.', 'vio-woocommerce-sync' )
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT key_id FROM {$table} WHERE description LIKE %s", $like )
		);

		foreach ( $rows as $row ) {
			$wpdb->delete( $table, [ 'key_id' => $row->key_id ], [ '%d' ] );
			Logger::info( 'REST API key removed: ' . $row->key_id );
		}
	}
}
