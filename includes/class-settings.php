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
		$has_key = Api_Client::has_api_key();
		$user    = $has_key ? Api_Client::get_current_user() : null;
		$valid   = $user && ! is_wp_error( $user ) && isset( $user->id );

		// Fully connected (valid API key + store webhook present): show only the
		// success notice and the Disconnect action — no fields, no Connect button.
		if ( $valid && self::webhook_exists() ) {
			self::render_connected( $user );
			return;
		}

		// Not fully connected → show the configuration fields.
		woocommerce_admin_fields( self::get_settings() );

		if ( ! $has_key ) {
			self::render_signup();
			return;
		}

		if ( ! $valid ) {
			echo '<p class="vio-error">' . esc_html__( 'The API Key is not valid.', 'vio-woocommerce-sync' ) . '</p>';
			return;
		}

		// Valid API key but the store webhook isn't set up yet → let the user authorize.
		$auth_url = Api_Client::authorization_url( (int) $user->id );
		echo '<p class="vio-connect">';
		printf(
			/* translators: %s: username */
			esc_html__( 'Connected to Vio as %s. Authorize the store to finish setup:', 'vio-woocommerce-sync' ),
			esc_html( $user->username ?? '' )
		);
		echo ' <a class="button button-primary" href="' . esc_url( $auth_url ) . '">'
			. esc_html__( 'Connect store', 'vio-woocommerce-sync' ) . '</a></p>';
	}

	private static function render_connected( object $user ): void {
		$currency   = (string) get_option( Plugin::OPT_CURRENCY );
		$logout_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=vio_logout' ), 'vio_logout' );

		echo '<div class="notice notice-success inline vio-connected">';
		echo '<h2 class="vio-connected__title">&#10003; ' . esc_html__( 'Your store is connected to Vio', 'vio-woocommerce-sync' ) . '</h2>';
		echo '<ul class="vio-connected__details">';
		echo '<li>' . esc_html( sprintf( /* translators: %s: Vio account/username */ __( 'Account: %s', 'vio-woocommerce-sync' ), $user->username ?? '' ) ) . '</li>';
		if ( '' !== $currency ) {
			echo '<li>' . esc_html( sprintf( /* translators: %s: currency code */ __( 'Currency: %s', 'vio-woocommerce-sync' ), $currency ) ) . '</li>';
		}
		echo '<li>' . esc_html__( 'REST API key created · Order webhooks active', 'vio-woocommerce-sync' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '<p class="vio-connected__actions">';
		echo '<a id="sync-all-button" class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">'
			. esc_html__( 'Go to products', 'vio-woocommerce-sync' ) . '</a> ';
		echo '<a class="button button-link-delete" href="' . esc_url( $logout_url ) . '">'
			. esc_html__( 'Disconnect', 'vio-woocommerce-sync' ) . '</a>';
		echo '</p>';
	}

	private static function render_signup(): void {
		// @TODO Real Vio sign-up URL.
		$signup = (string) apply_filters( 'vio_wc_sync_signup_url', 'https://vio.live/' );
		echo '<p class="vio-signup">' . esc_html__( "Don't have an account yet?", 'vio-woocommerce-sync' )
			. ' <a href="' . esc_url( $signup ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Sign up', 'vio-woocommerce-sync' ) . '</a></p>';
	}

	private static function webhook_exists(): bool {
		$data_store = \WC_Data_Store::load( 'webhook' );
		foreach ( $data_store->search_webhooks( array( 'limit' => -1 ) ) as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( ! $webhook ) {
				continue;
			}
			// Match by one of the managed names…
			if ( in_array( $webhook->get_name(), Plugin::WEBHOOK_NAMES, true ) ) {
				return true;
			}
			// …or by a delivery URL whose host matches the configured Vio backend
			// (the backend creates the webhook and may name it differently).
			$url = (string) $webhook->get_delivery_url();
			if ( '' !== $url ) {
				$delivery_host = (string) wp_parse_url( $url, PHP_URL_HOST );
				$backend_host  = (string) wp_parse_url( Api_Client::base_url(), PHP_URL_HOST );
				if ( '' !== $delivery_host && $delivery_host === $backend_host ) {
					return true;
				}
			}
		}
		return false;
	}
}
