<?php
/**
 * Store status / data service for the Vio configuration page.
 *
 * Pure logic, no HTML: connection state, sync stats, eligible products,
 * settings persistence and diagnostics. The page (Config_Page) and the AJAX
 * endpoints (Ajax) both read from here, and the test suite targets it directly.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Store_Status {

	/**
	 * Resolve the live connection state (one network call to the Vio API).
	 *
	 * @return array{has_key:bool,reachable:bool,valid:bool,connected:bool,status:int,user:?object,latency:int}
	 */
	public static function connection_state(): array {
		$has_key = Api_Client::has_api_key();
		$user    = null;
		$latency = 0;

		$status = 0;
		if ( $has_key ) {
			$start   = microtime( true );
			$user    = Api_Client::get_current_user();
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $user ) ) {
				$data   = $user->get_error_data();
				$status = ( is_array( $data ) && ! empty( $data['status'] ) ) ? (int) $data['status'] : 0;
			} else {
				$status = 200;
			}
		}

		// "reachable" = the API answered at all (even a 401); only a network
		// failure leaves status 0. "valid" = the key was accepted (200 + id).
		$reachable = $has_key && $status > 0;
		$valid     = 200 === $status && is_object( $user ) && isset( $user->id );
		$connected = $valid && self::webhook_exists();

		return [
			'has_key'   => $has_key,
			'reachable' => $reachable,
			'valid'     => $valid,
			'connected' => $connected,
			'status'    => $status,
			'user'      => is_object( $user ) ? $user : null,
			'latency'   => $latency,
		];
	}

	/**
	 * A human message explaining why the connection isn't valid (empty if OK).
	 */
	public static function connection_message( array $state ): string {
		if ( empty( $state['has_key'] ) || ! empty( $state['valid'] ) ) {
			return '';
		}
		$status = (int) ( $state['status'] ?? 0 );
		if ( in_array( $status, array( 401, 403 ), true ) ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'Your Vio API key was rejected (HTTP %d). Paste the correct key under Settings and save.', 'vio-woocommerce-sync' ), $status );
		}
		if ( 0 === $status ) {
			return __( 'Could not reach Vio (network error). Check the connection and re-check.', 'vio-woocommerce-sync' );
		}
		/* translators: %d: HTTP status code */
		return sprintf( __( 'Vio returned HTTP %d. Verify the key and environment, then re-check.', 'vio-woocommerce-sync' ), $status );
	}

	/**
	 * Store-wide sync stats from product meta.
	 *
	 * @return array{total:int,synced:int,sent:int,not_synced:int}
	 */
	public static function stats(): array {
		global $wpdb;

		$total = (int) wp_count_posts( 'product' )->publish;

		// Synced: a remote product-id was written back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$synced = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT pm.post_id)
			   FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = 'vio-product-id' AND pm.meta_value <> ''
			    AND p.post_type = 'product' AND p.post_status = 'publish'"
		);

		// Sent: queued (sqs id) but no product-id yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$sent = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT pm.post_id)
			   FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = 'vio-sqs-id' AND pm.meta_value <> ''
			    AND p.post_type = 'product' AND p.post_status = 'publish'
			    AND NOT EXISTS (
			        SELECT 1 FROM {$wpdb->postmeta} pm2
			         WHERE pm2.post_id = pm.post_id
			           AND pm2.meta_key = 'vio-product-id' AND pm2.meta_value <> ''
			    )"
		);

		return [
			'total'      => $total,
			'synced'     => $synced,
			'sent'       => $sent,
			'not_synced' => max( 0, $total - $synced - $sent ),
		];
	}

	/**
	 * Link products the backend already created. After a product is created the
	 * backend writes its Vio id back into the WooCommerce post under the legacy
	 * `reachu-product-id` meta (the old plugin's key); copy it into
	 * META_PRODUCT_ID so the Sent→Synced transition, the sync stats and the
	 * auto-update/delete flows — all keyed on META_PRODUCT_ID — work.
	 *
	 * Idempotent and cheap (one indexed query, a write only for new matches),
	 * so it is safe to call on every page load and stats fetch.
	 *
	 * @return int Number of products linked this run.
	 */
	public static function reconcile_remote_ids(): int {
		// 1) Free fast-path: the backend's write-back into the legacy meta.
		$linked = self::reconcile_from_writeback();

		// 2) Fallback: ask the backend which still-queued products are synced.
		//    Only fires a request when something is actually pending.
		if ( Api_Client::has_api_key() ) {
			$linked += self::reconcile_from_lookup();
		}

		if ( $linked > 0 ) {
			Logger::info( '[reconcile] linked ' . $linked . ' product(s) to Vio' );
		}
		return $linked;
	}

	/**
	 * Fast-path: the backend writes the Vio id into the legacy `reachu-product-id`
	 * meta; copy it into META_PRODUCT_ID. Pure local query — no network.
	 */
	private static function reconcile_from_writeback(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT legacy.post_id AS post_id, legacy.meta_value AS remote_id
				   FROM {$wpdb->postmeta} legacy
				  WHERE legacy.meta_key = %s AND legacy.meta_value <> ''
				    AND NOT EXISTS (
				        SELECT 1 FROM {$wpdb->postmeta} synced
				         WHERE synced.post_id = legacy.post_id
				           AND synced.meta_key = %s AND synced.meta_value <> ''
				    )",
				Plugin::META_LEGACY_PRODUCT_ID,
				Plugin::META_PRODUCT_ID
			)
		);

		foreach ( (array) $rows as $row ) {
			update_post_meta( (int) $row->post_id, Plugin::META_PRODUCT_ID, (string) $row->remote_id );
		}
		return count( (array) $rows );
	}

	/**
	 * Fallback: for products still "Sent" (queued but without an id), ask the
	 * backend's validate-synced endpoint by origin id and write the returned
	 * vioId. Skips the network call entirely when nothing is pending.
	 */
	private static function reconcile_from_lookup(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sent.post_id
				   FROM {$wpdb->postmeta} sent
				  WHERE sent.meta_key = %s AND sent.meta_value <> ''
				    AND NOT EXISTS (
				        SELECT 1 FROM {$wpdb->postmeta} synced
				         WHERE synced.post_id = sent.post_id
				           AND synced.meta_key = %s AND synced.meta_value <> ''
				    )",
				Plugin::META_SQS_ID,
				Plugin::META_PRODUCT_ID
			)
		);
		if ( empty( $post_ids ) ) {
			return 0;
		}

		$linked = 0;
		foreach ( array_chunk( array_map( 'intval', $post_ids ), 50 ) as $batch ) {
			$result = Api_Client::validate_synced( $batch );
			if ( is_wp_error( $result ) || ! is_array( $result ) ) {
				continue;
			}
			foreach ( $result as $entry ) {
				if ( isset( $entry->originId, $entry->vioId ) && ! empty( $entry->synced ) ) {
					update_post_meta( (int) $entry->originId, Plugin::META_PRODUCT_ID, (string) $entry->vioId );
					++$linked;
				}
			}
		}
		return $linked;
	}

	/**
	 * JSON-friendly connection snapshot for the "Re-check" action.
	 *
	 * @return array<string,mixed>
	 */
	public static function health_payload(): array {
		$s       = self::connection_state();
		$user    = $s['user'];
		$account = '';
		if ( $user ) {
			$account = isset( $user->username ) ? (string) $user->username : ( isset( $user->email ) ? (string) $user->email : '' );
		}

		return [
			'hasKey'      => $s['has_key'],
			'reachable'   => $s['reachable'],
			'valid'       => $s['valid'],
			'connected'   => $s['connected'],
			'status'      => $s['status'],
			'latency'     => $s['latency'],
			'account'     => $account,
			'environment' => Api_Client::environment(),
			'host'        => (string) wp_parse_url( Api_Client::base_url(), PHP_URL_HOST ),
			'message'     => self::connection_message( $s ),
		];
	}

	/**
	 * Published product ids eligible to sync: not yet synced (no product-id)
	 * and not imported from Vio. Drives the "Sync all" action.
	 *
	 * @return int[]
	 */
	public static function pending_product_ids( int $limit = 1000 ): array {
		global $wpdb;
		$limit = max( 1, min( 5000, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				  WHERE p.post_type = 'product' AND p.post_status = 'publish'
				    AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m
				        WHERE m.post_id = p.ID AND m.meta_key = 'vio-product-id' AND m.meta_value <> '')
				    AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m2
				        WHERE m2.post_id = p.ID AND m2.meta_key = 'vio-origin' AND m2.meta_value <> '')
				  ORDER BY p.ID ASC
				  LIMIT %d",
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Persist the settings (api key, environment, currency) and push the
	 * currency to the backend when a key is present. Invalid environments are
	 * ignored so a bad value can't silently switch the API target.
	 */
	public static function save_options( string $apikey, string $env, string $currency ): void {
		update_option( Plugin::OPT_API_KEY, $apikey );
		if ( isset( Api_Client::ENVIRONMENTS[ $env ] ) ) {
			update_option( Plugin::OPT_ENVIRONMENT, $env );
		}
		update_option( Plugin::OPT_CURRENCY, $currency );

		if ( '' !== $apikey ) {
			Api_Client::save_config( [ 'currency' => $currency ] );
		}
	}

	/**
	 * @return array<string,string>
	 */
	private static function diagnostics(): array {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$override = defined( 'VIO_WC_SYNC_ENV' ) ? (string) VIO_WC_SYNC_ENV : '';

		return [
			'Plugin'       => VIO_WC_SYNC_VERSION,
			'WooCommerce'  => defined( 'WC_VERSION' ) ? WC_VERSION : '—',
			'WordPress'    => get_bloginfo( 'version' ),
			'PHP'          => PHP_VERSION,
			'HPOS'         => $hpos ? __( 'enabled', 'vio-woocommerce-sync' ) : __( 'disabled', 'vio-woocommerce-sync' ),
			'Env override' => '' !== $override ? $override : __( 'none', 'vio-woocommerce-sync' ),
		];
	}

	/**
	 * Diagnostics as one line, used in the logs "Copy for support" payload.
	 */
	public static function diag_string(): string {
		$parts = array();
		foreach ( self::diagnostics() as $label => $value ) {
			$parts[] = $label . ': ' . $value;
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Whether a Vio order webhook is present — matched by a managed name, or by a
	 * delivery URL whose host equals the backend (the backend may rename them).
	 */
	public static function webhook_exists(): bool {
		$data_store = \WC_Data_Store::load( 'webhook' );
		foreach ( $data_store->search_webhooks( array( 'limit' => -1 ) ) as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( ! $webhook ) {
				continue;
			}
			if ( in_array( $webhook->get_name(), Plugin::WEBHOOK_NAMES, true ) ) {
				return true;
			}
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

	/**
	 * Currency dropdown options from the backend (enabled currencies only).
	 *
	 * @return array<string,string>
	 */
	public static function currency_options(): array {
		$options  = array( '' => '--' );
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
}
