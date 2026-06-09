<?php
/**
 * Cliente HTTP de la API de Vio.
 *
 * Gestiona la selección de entorno (production / staging), la autenticación y
 * las llamadas a cada endpoint.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Api_Client {

	/**
	 * URLs base por entorno.
	 *
	 * Hoy apuntan a la plataforma Reachu. Cuando Vio exponga su dominio
	 * (p. ej. https://api-commerce.vio.live) la migración es de una sola línea
	 * aquí — o sin tocar código definiendo la constante en wp-config.php:
	 *
	 *   define( 'VIO_WC_SYNC_API_URL_PRODUCTION', 'https://api-commerce.vio.live' );
	 *   define( 'VIO_WC_SYNC_API_URL_STAGING',    'https://api-staging-commerce.vio.live' );
	 */
	public const ENVIRONMENTS = [
		'production' => 'https://api.reachu.io',
		'staging'    => 'https://api-qa.reachu.io',
	];

	public const DEFAULT_ENVIRONMENT = 'production';

	private const TIMEOUT = 15;

	/**
	 * Entorno activo, en cascada:
	 *   1) constante VIO_WC_SYNC_ENV en wp-config.php
	 *   2) opción guardada en ajustes
	 *   3) default (production)
	 */
	public static function environment(): string {
		if ( defined( 'VIO_WC_SYNC_ENV' ) && isset( self::ENVIRONMENTS[ VIO_WC_SYNC_ENV ] ) ) {
			return VIO_WC_SYNC_ENV;
		}
		$env = (string) get_option( Plugin::OPT_ENVIRONMENT, self::DEFAULT_ENVIRONMENT );
		return isset( self::ENVIRONMENTS[ $env ] ) ? $env : self::DEFAULT_ENVIRONMENT;
	}

	public static function base_url(): string {
		$env = self::environment();

		// 1) Constante específica por entorno en wp-config.php (override sin tocar código).
		$constant = 'VIO_WC_SYNC_API_URL_' . strtoupper( $env );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			$base = (string) constant( $constant );
		} else {
			$base = self::ENVIRONMENTS[ $env ] ?? self::ENVIRONMENTS[ self::DEFAULT_ENVIRONMENT ];
		}

		// 2) Filtro de override total (p. ej. local/ngrok o un dominio nuevo).
		return untrailingslashit( (string) apply_filters( 'vio_wc_sync_api_base', $base, $env ) );
	}

	public static function api_key(): string {
		return (string) get_option( Plugin::OPT_API_KEY, '' );
	}

	public static function has_api_key(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Llamada genérica a la API.
	 *
	 * @param string     $endpoint Path con barra inicial, p. ej. '/api/products'.
	 * @param string     $method   Verbo HTTP.
	 * @param array|null $body     Cuerpo a serializar como JSON.
	 * @return mixed Cuerpo decodificado, o \WP_Error en caso de fallo.
	 */
	public static function request( string $endpoint, string $method = 'GET', ?array $body = null ) {
		$url = self::base_url() . $endpoint;

		$args = [
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json; charset=utf-8',
				'Authorization' => self::api_key(),
			],
		];

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		Logger::info( sprintf( '[request] %s %s', $method, $url ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( '[request] %s %s → %s', $method, $url, $response->get_error_message() ) );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, [ 200, 201 ], true ) ) {
			Logger::error( sprintf( '[request] %s %s → HTTP %d', $method, $url, $code ) );
			return new \WP_Error( 'vio_api_error', sprintf( 'HTTP %d', $code ), [ 'status' => $code ] );
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	// --- Endpoints --------------------------------------------------------
	// Paths compartidos con la plataforma Reachu; se mantienen iguales para Vio.

	public static function get_current_user() {
		return self::request( '/catalog/users/me' );
	}

	public static function get_currencies() {
		return self::request( '/api/currencies' );
	}

	public static function save_config( array $config ) {
		return self::request( '/woo/config', 'PUT', $config );
	}

	public static function create_products( array $payload ) {
		return self::request( '/api/products/create-sqs', 'POST', $payload );
	}

	public static function get_product( string $product_id ) {
		return self::request( '/api/products/' . rawurlencode( $product_id ) );
	}

	public static function update_product( string $product_id, array $data ) {
		return self::request( '/api/products/' . rawurlencode( $product_id ), 'PUT', $data );
	}

	public static function delete_product( string $product_id ) {
		return self::request( '/api/products/' . rawurlencode( $product_id ), 'DELETE' );
	}

	public static function delete_products( array $product_ids ) {
		$ids = implode( ',', array_map( 'rawurlencode', $product_ids ) );
		return self::request( '/api/products?ids=' . $ids, 'DELETE' );
	}

	public static function finish_sync() {
		return self::request( '/api/users/me/finish-sync?origin=WOOCOMMERCE', 'PUT' );
	}

	/**
	 * URL de autorización OAuth de WooCommerce con callback hacia Vio.
	 */
	public static function authorization_url( int $user_id ): string {
		$current_path = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$parts      = explode( '/wp-admin', $current_path );
		$return_url = site_url( '/wp-admin' . ( $parts[1] ?? '' ) );

		$params = [
			'app_name'     => Plugin::API_KEY_DESCRIPTION,
			'scope'        => 'read_write',
			'user_id'      => $user_id,
			'return_url'   => $return_url,
			'callback_url' => self::base_url() . '/woo/auth/callback-supplier/',
		];

		return site_url() . '/wc-auth/v1/authorize?' . http_build_query( $params );
	}
}
