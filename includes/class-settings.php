<?php
/**
 * Vio settings tab under WooCommerce → Settings.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const TAB_ID = 'settings_vio';

	public static function init(): void {
		add_filter( 'woocommerce_settings_tabs_array', [ self::class, 'add_tab' ], 50 );
		add_action( 'woocommerce_settings_tabs_' . self::TAB_ID, [ self::class, 'render' ] );
		add_action( 'woocommerce_update_options_' . self::TAB_ID, [ self::class, 'update' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function add_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = __( 'Vio', 'vio-woocommerce-sync' );
		return $tabs;
	}

	public static function enqueue( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'wc-settings' !== $page || self::TAB_ID !== $tab ) {
			return;
		}

		wp_enqueue_style( 'vio-wc-sync-admin', VIO_WC_SYNC_URL . 'assets/css/admin.css', [], VIO_WC_SYNC_VERSION );
		wp_enqueue_script( 'vio-wc-sync-settings', VIO_WC_SYNC_URL . 'assets/js/settings.js', [ 'jquery' ], VIO_WC_SYNC_VERSION, true );
		wp_localize_script(
			'vio-wc-sync-settings',
			'vioWcSync',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vio_sync' ),
			]
		);
	}

	/**
	 * Field definition (WooCommerce settings API).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_settings(): array {
		return (array) apply_filters(
			'vio_wc_sync_settings',
			[
				'section_title' => [
					'name' => __( 'Vio WooCommerce Sync', 'vio-woocommerce-sync' ),
					'type' => 'title',
					'desc' => __( 'Connect your store with Vio and sync your catalog.', 'vio-woocommerce-sync' ),
					'id'   => 'vio_section',
				],
				'apikey'        => [
					'name' => __( 'API Key', 'vio-woocommerce-sync' ),
					'type' => 'text',
					'id'   => Plugin::OPT_API_KEY,
				],
				'environment'   => [
					'name'    => __( 'Environment', 'vio-woocommerce-sync' ),
					'type'    => 'select',
					'id'      => Plugin::OPT_ENVIRONMENT,
					'default' => Api_Client::DEFAULT_ENVIRONMENT,
					'desc'    => __( 'Vio API environment. Can be forced with the VIO_WC_SYNC_ENV constant in wp-config.php.', 'vio-woocommerce-sync' ),
					'options' => [
						'production' => __( 'Production', 'vio-woocommerce-sync' ),
						'staging'    => __( 'Staging', 'vio-woocommerce-sync' ),
					],
				],
				'currency'      => [
					'name'    => __( 'Currency', 'vio-woocommerce-sync' ),
					'type'    => 'select',
					'id'      => Plugin::OPT_CURRENCY,
					'default' => '',
					'options' => self::currency_options(),
				],
				'section_end'   => [
					'type' => 'sectionend',
					'id'   => 'vio_section',
				],
			]
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function currency_options(): array {
		$options  = [ '' => '--' ];
		$response = Api_Client::get_currencies();
		if ( is_array( $response ) ) {
			foreach ( $response as $currency ) {
				if ( isset( $currency->enabled, $currency->currency_code ) && 1 == $currency->enabled ) { // phpcs:ignore WordPress.PHP.StrictComparisons
					$options[ $currency->currency_code ] = $currency->currency_code;
				}
			}
		}
		return $options;
	}

	public static function update(): void {
		woocommerce_update_options( self::get_settings() );
	}

	public static function render(): void {
		if ( ! Api_Client::has_api_key() ) {
			woocommerce_admin_fields( self::get_settings() );
			self::render_signup();
			return;
		}

		$user = Api_Client::get_current_user();
		if ( is_wp_error( $user ) || ! isset( $user->id ) ) {
			woocommerce_admin_fields( self::get_settings() );
			echo '<p class="vio-error">' . esc_html__( 'The API Key is not valid.', 'vio-woocommerce-sync' ) . '</p>';
			return;
		}

		// Valid API key but no webhook yet → the store still needs to connect via OAuth.
		if ( ! self::webhook_exists() ) {
			$auth_url = Api_Client::authorization_url( (int) $user->id );
			echo '<p class="vio-connect"><a class="button button-primary" href="' . esc_url( $auth_url ) . '">'
				. esc_html__( 'Connect store', 'vio-woocommerce-sync' ) . '</a></p>';
			return;
		}

		self::render_connected( $user );
	}

	private static function render_connected( object $user ): void {
		$currency   = (string) get_option( Plugin::OPT_CURRENCY );
		$logout_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=vio_logout' ), 'vio_logout' );

		echo '<h2>' . esc_html__( 'Your store is connected', 'vio-woocommerce-sync' ) . '</h2>';
		echo '<p class="vio-account">'
			. esc_html( sprintf( /* translators: %s: username */ __( 'Connected as %s', 'vio-woocommerce-sync' ), $user->username ?? '' ) )
			. ( '' !== $currency ? ' · ' . esc_html( $currency ) : '' )
			. '</p>';
		echo '<p><a id="sync-all-button" class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">'
			. esc_html__( 'Go to products', 'vio-woocommerce-sync' ) . '</a> '
			. '<a class="button" href="' . esc_url( $logout_url ) . '">' . esc_html__( 'Disconnect', 'vio-woocommerce-sync' ) . '</a></p>';
	}

	private static function render_signup(): void {
		// @TODO Real Vio sign-up URL.
		$signup = (string) apply_filters( 'vio_wc_sync_signup_url', 'https://reachu.io/' );
		echo '<p class="vio-signup">' . esc_html__( "Don't have an account yet?", 'vio-woocommerce-sync' )
			. ' <a href="' . esc_url( $signup ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Sign up', 'vio-woocommerce-sync' ) . '</a></p>';
	}

	private static function webhook_exists(): bool {
		$data_store = \WC_Data_Store::load( 'webhook' );
		foreach ( $data_store->search_webhooks() as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( $webhook && in_array( $webhook->get_name(), Plugin::WEBHOOK_NAMES, true ) ) {
				return true;
			}
		}
		return false;
	}
}
