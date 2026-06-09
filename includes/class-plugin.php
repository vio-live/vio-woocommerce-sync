<?php
/**
 * Orquestador del plugin: constantes centrales, arranque y ciclo de vida.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const TEXT_DOMAIN = 'vio-woocommerce-sync';
	public const LOG_SOURCE   = 'vio-woocommerce-sync';

	/** Capability requerida para todas las operaciones de sincronización. */
	public const CAPABILITY = 'manage_woocommerce';

	// --- Opciones en base de datos ---------------------------------------
	public const OPT_API_KEY     = 'vio_apikey';
	public const OPT_CURRENCY    = 'vio_currency';
	public const OPT_ENVIRONMENT = 'vio_environment';

	// --- Post meta keys ---------------------------------------------------
	public const META_UID        = 'vio-uid';
	public const META_APIKEY     = 'vio-apikey';
	public const META_PRODUCT_ID = 'vio-product-id';
	public const META_SQS_ID     = 'vio-sqs-id';
	public const META_ORIGIN     = 'vio-origin';

	/** Nombres de los webhooks de órdenes gestionados por Vio. */
	public const WEBHOOK_NAMES = [ 'Vio order.created', 'Vio order.updated' ];

	/** Texto identificador de las API keys REST creadas para Vio. */
	public const API_KEY_DESCRIPTION = 'Vio WooCommerce Sync';

	/**
	 * Arranque: se ejecuta en plugins_loaded.
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

		Settings::init();
		Products_Table::init();
		Ajax::init();
	}

	public static function woocommerce_missing_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Vio WooCommerce Sync requiere que WooCommerce esté instalado y activo.', 'vio-woocommerce-sync' )
		);
	}

	// --- Ciclo de vida ----------------------------------------------------

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
	 * Limpieza al desconectar/desactivar: elimina webhooks y API keys de Vio.
	 */
	public static function cleanup(): void {
		self::remove_webhooks();
		self::remove_api_keys();
	}

	private static function remove_webhooks(): void {
		$data_store = \WC_Data_Store::load( 'webhook' );
		foreach ( $data_store->search_webhooks() as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( $webhook && in_array( $webhook->get_name(), self::WEBHOOK_NAMES, true ) ) {
				$webhook->delete( true );
				Logger::info( 'Webhook eliminado: ' . $webhook->get_name() );
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
			Logger::info( 'API key REST eliminada: ' . $row->key_id );
		}
	}
}
