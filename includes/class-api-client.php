<?php
/**
 * HTTP client for the Vio API.
 *
 * Handles environment selection (production / staging), authentication and the
 * call to each endpoint.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Api_Client {

	/**
	 * Base URLs per environment (Vio commerce backend).
	 *
	 * Each environment can be overridden from wp-config.php (zero code change):
	 *
	 *   define( 'VIO_WC_SYNC_API_URL_PRODUCTION', 'https://api-ecom.vio.live' );
	 *   define( 'VIO_WC_SYNC_API_URL_STAGING',    'https://api-ecom-staging.vio.live' );
	 */
	public const ENVIRONMENTS = [
		'production' => 'https://api-ecom.vio.live',
		'staging'    => 'https://api-ecom-staging.vio.live',
	];

	public const DEFAULT_ENVIRONMENT = 'production';

	private const TIMEOUT = 15;

	// --- API endpoints ----------------------------------------------------
	// Every backend path the plugin calls lives here. To change or add one,
	// edit this block — the methods below only reference these constants.
	private const EP_CURRENT_USER   = '/catalog/users/me';                            // GET    — whoami / health
	private const EP_CURRENCIES     = '/api/currencies';                              // GET    — enabled currencies
	private const EP_CONFIG         = '/woo/config';                                  // PUT    — store config (currency)
	private const EP_CREATE_SQS     = '/api/products/create-sqs';                     // POST   — queue product create → { messageId }
	private const EP_PRODUCTS        = '/api/products';                               // GET …/{id} · PUT …/{id} · DELETE …/{id} · DELETE ?ids=
	private const EP_VALIDATE_SYNCED = '/api/product/validate-synced';                // GET …/{origin}?originIds= → [{originId,synced,vioId,vioActive}]
	private const EP_FINISH_SYNC    = '/api/users/me/finish-sync?origin=WOOCOMMERCE'; // PUT    — mark first sync done
	private const EP_API_CREDENTIAL = '/api/users/api-credential/';                   // DELETE — remove the connection + API key
	private const EP_ECOM_USER      = '/api/ecom-user';                               // GET    — account's store connections + their apiCredential ids
	private const EP_OAUTH_CALLBACK = '/woo/auth/callback-supplier/';                 // OAuth  — backend receives the WC REST key

	/**
	 * Active environment, resolved in cascade:
	 *   1) VIO_WC_SYNC_ENV constant in wp-config.php
	 *   2) the option saved in settings
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

		// 1) Per-environment constant in wp-config.php (override without touching code).
		$constant = 'VIO_WC_SYNC_API_URL_' . strtoupper( $env );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			$base = (string) constant( $constant );
		} else {
			$base = self::ENVIRONMENTS[ $env ] ?? self::ENVIRONMENTS[ self::DEFAULT_ENVIRONMENT ];
		}

		// 2) Full override filter (e.g. local/ngrok or a brand-new domain).
		return untrailingslashit( (string) apply_filters( 'vio_wc_sync_api_base', $base, $env ) );
	}

	public static function api_key(): string {
		return (string) get_option( Plugin::OPT_API_KEY, '' );
	}

	public static function has_api_key(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Generic API call.
	 *
	 * @param string     $endpoint Path with a leading slash, e.g. '/api/products'.
	 * @param string     $method   HTTP verb.
	 * @param array|null $body     Payload to serialize as JSON.
	 * @return mixed Decoded body, or \WP_Error on failure.
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
	// Thin wrappers; the paths all live in the EP_* constants above.

	public static function get_current_user() {
		return self::request( self::EP_CURRENT_USER );
	}

	public static function get_currencies() {
		return self::request( self::EP_CURRENCIES );
	}

	public static function save_config( array $config ) {
		return self::request( self::EP_CONFIG, 'PUT', $config );
	}

	public static function create_products( array $payload ) {
		return self::request( self::EP_CREATE_SQS, 'POST', $payload );
	}

	public static function get_product( string $product_id ) {
		return self::request( self::EP_PRODUCTS . '/' . rawurlencode( $product_id ) );
	}

	public static function update_product( string $product_id, array $data ) {
		return self::request( self::EP_PRODUCTS . '/' . rawurlencode( $product_id ), 'PUT', $data );
	}

	public static function delete_product( string $product_id ) {
		return self::request( self::EP_PRODUCTS . '/' . rawurlencode( $product_id ), 'DELETE' );
	}

	public static function delete_products( array $product_ids ) {
		$ids = implode( ',', array_map( 'rawurlencode', $product_ids ) );
		return self::request( self::EP_PRODUCTS . '?ids=' . $ids, 'DELETE' );
	}

	/**
	 * Resolve which WooCommerce products the backend already created, by origin id
	 * — the fallback for the product-id write-back. Env-agnostic (uses base_url()),
	 * so it follows the host automatically when the API moves to vio.live.
	 *
	 * @param int[]|string[] $origin_ids WooCommerce post ids.
	 * @return mixed [{ originId, synced, vioId, vioActive }] or \WP_Error.
	 */
	public static function validate_synced( array $origin_ids, string $origin = 'WOOCOMMERCE' ) {
		$ids = implode( ',', array_map( 'rawurlencode', $origin_ids ) );
		return self::request( self::EP_VALIDATE_SYNCED . '/' . rawurlencode( $origin ) . '?originIds=' . $ids );
	}

	public static function finish_sync() {
		return self::request( self::EP_FINISH_SYNC, 'PUT' );
	}

	/**
	 * Remove the store's connection + API credential on the backend (disconnect).
	 * Mirrors the manual call: { fullDelete: true, id: <credentialId>, ecomUser: { id: <userId> } }.
	 */
	public static function delete_api_credential( int $credential_id, int $ecom_user_id ) {
		return self::request(
			self::EP_API_CREDENTIAL,
			'DELETE',
			[
				'fullDelete' => true,
				'id'         => $credential_id,
				'ecomUser'   => [ 'id' => $ecom_user_id ],
			]
		);
	}

	/**
	 * Resolve this store's WooCommerce connection from GET /api/ecom-user, returning
	 * the two ids the disconnect needs: the apiCredential id and the ecom-user id.
	 *
	 * Note: `ecom_user_id` is the **entry's own `id`** in /api/ecom-user (e.g. 199),
	 * NOT the Vio account id from /catalog/users/me (e.g. 1289) — the backend's
	 * DELETE payload keys off the former.
	 *
	 * @return array{credential_id:int,ecom_user_id:int}|null
	 */
	public static function find_woo_connection(): ?array {
		$list = self::request( self::EP_ECOM_USER );
		if ( is_wp_error( $list ) || ! is_array( $list ) ) {
			return null;
		}
		return self::pick_woo_connection( $list, (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
	}

	/**
	 * Pick this store's WooCommerce connection from a decoded /api/ecom-user list,
	 * returning the ids the disconnect needs. Pure (no I/O) so it is unit-tested.
	 *
	 * `ecom_user_id` is each entry's own `id` (e.g. 199) — NOT the Vio account id
	 * from /catalog/users/me (e.g. 1289), which the backend rejects with HTTP 417.
	 *
	 * @param array  $list Decoded /api/ecom-user entries (objects).
	 * @param string $host This store's host, to disambiguate multiple connections.
	 * @return array{credential_id:int,ecom_user_id:int}|null
	 */
	public static function pick_woo_connection( array $list, string $host ): ?array {
		$fallback = null;

		foreach ( $list as $entry ) {
			if ( ! is_object( $entry ) || ! isset( $entry->id, $entry->ecomName, $entry->apiCredential->id ) || 'WOOCOMMERCE' !== $entry->ecomName ) {
				continue;
			}

			$match    = array(
				'credential_id' => (int) $entry->apiCredential->id,
				'ecom_user_id'  => (int) $entry->id,
			);
			$fallback = $fallback ?? $match;

			// Prefer the connection whose URL host matches this store.
			$url_host = isset( $entry->connection->url ) ? (string) wp_parse_url( $entry->connection->url, PHP_URL_HOST ) : '';
			if ( '' !== $host && $url_host === $host ) {
				return $match;
			}
		}

		return $fallback;
	}

	/**
	 * WooCommerce OAuth authorization URL with a callback to Vio.
	 */
	public static function authorization_url( int $user_id, string $return_url = '' ): string {
		// Where WooCommerce sends the browser after approval. Must be an explicit
		// admin URL — deriving it from REQUEST_URI breaks when this runs inside an
		// AJAX request (it would point back at admin-ajax.php and render a blank "0").
		if ( '' === $return_url ) {
			$return_url = admin_url( 'admin.php?page=vio' );
		}

		$params = [
			'app_name'     => Plugin::API_KEY_DESCRIPTION,
			'scope'        => 'read_write',
			'user_id'      => $user_id,
			'return_url'   => $return_url,
			'callback_url' => self::base_url() . self::EP_OAUTH_CALLBACK,
		];

		return site_url() . '/wc-auth/v1/authorize?' . http_build_query( $params );
	}
}
