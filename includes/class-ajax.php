<?php
/**
 * AJAX actions. All require an authenticated user with capability + nonce
 * (no `nopriv` variants are registered).
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	public static function init(): void {
		add_action( 'wp_ajax_vio_sync', [ self::class, 'sync' ] );
		add_action( 'wp_ajax_vio_delete', [ self::class, 'delete' ] );
		add_action( 'wp_ajax_vio_finish_sync', [ self::class, 'finish_sync' ] );
		add_action( 'wp_ajax_vio_save_settings', [ self::class, 'save_settings' ] );
		add_action( 'wp_ajax_vio_logout', [ self::class, 'logout' ] );
		add_action( 'wp_ajax_vio_save_config', [ self::class, 'save_config' ] );
		add_action( 'wp_ajax_vio_health', [ self::class, 'health' ] );
		add_action( 'wp_ajax_vio_stats', [ self::class, 'stats' ] );
		add_action( 'wp_ajax_vio_pending_ids', [ self::class, 'pending_ids' ] );
		add_action( 'wp_ajax_vio_logs', [ self::class, 'logs' ] );
		add_action( 'wp_ajax_vio_connect', [ self::class, 'connect' ] );
	}

	/** Checks capability; bails with 403 if the user lacks it. */
	private static function guard(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
	}

	/** @return int[] */
	private static function post_ids(): array {
		$ids = isset( $_POST['id_posts'] ) ? (array) wp_unslash( $_POST['id_posts'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	public static function sync(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();

		$post_ids = self::post_ids();
		if ( empty( $post_ids ) ) {
			wp_send_json_error( [ 'message' => 'no_ids' ] );
		}

		Logger::info( '[sync] postIds ' . implode( ',', $post_ids ) );
		if ( Sync::push_products( $post_ids, Api_Client::api_key() ) ) {
			wp_send_json_success();
		}
		wp_send_json_error( array( 'message' => 'sync_failed' ) );
	}

	public static function delete(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();

		$post_ids = self::post_ids();
		$api_key  = Api_Client::api_key();
		if ( empty( $post_ids ) || '' === $api_key ) {
			wp_send_json_error( [ 'message' => 'no_ids' ] );
		}

		// Batch-delete the known remote products, and unlink every selected post
		// locally — including "Sent"-only ones that have no remote id yet.
		$remote_ids = array();
		foreach ( $post_ids as $post_id ) {
			$remote_id = Product_Mapper::get_remote_product_id( $api_key, $post_id );
			if ( $remote_id ) {
				$remote_ids[] = $remote_id;
			}
			Sync::delete_by_post( $post_id, $api_key, false );
		}

		if ( $remote_ids ) {
			Api_Client::delete_products( $remote_ids );
		}

		Logger::info( '[delete] unlinked posts ' . implode( ',', $post_ids ) );
		wp_send_json_success();
	}

	public static function finish_sync(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();

		Logger::info( '[finish_sync] notifying first-sync completion' );
		Api_Client::finish_sync();
		wp_send_json_success();
	}

	public static function save_settings(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();

		$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : '';
		Api_Client::save_config( [ 'currency' => $currency ] );
		update_option( Plugin::OPT_CURRENCY, $currency );
		wp_send_json_success();
	}

	public static function save_config(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();

		$apikey   = isset( $_POST['apikey'] ) ? sanitize_text_field( wp_unslash( $_POST['apikey'] ) ) : '';
		$env      = isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : '';
		$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : '';

		Store_Status::save_options( $apikey, $env, $currency );

		Logger::info( '[save_config] settings saved' );
		wp_send_json_success();
	}

	/**
	 * One-step connect: save the settings, validate the key, and hand back the
	 * WooCommerce OAuth authorize URL for the browser to follow. On an invalid
	 * key, return the same status-aware message the page shows.
	 */
	public static function connect(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();

		$apikey   = isset( $_POST['apikey'] ) ? sanitize_text_field( wp_unslash( $_POST['apikey'] ) ) : '';
		$env      = isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : '';
		$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : '';

		Store_Status::save_options( $apikey, $env, $currency );

		$user = Api_Client::get_current_user();
		if ( is_wp_error( $user ) || ! is_object( $user ) || ! isset( $user->id ) ) {
			$data    = is_wp_error( $user ) ? $user->get_error_data() : array();
			$status  = ( is_array( $data ) && ! empty( $data['status'] ) ) ? (int) $data['status'] : 0;
			$message = Store_Status::connection_message( array( 'has_key' => '' !== $apikey, 'valid' => false, 'status' => $status ) );
			Logger::error( '[connect] key rejected (HTTP ' . $status . ')' );
			wp_send_json_error( array( 'message' => $message, 'status' => $status ) );
		}

		Logger::info( '[connect] key valid, handing OAuth authorize URL' );
		wp_send_json_success( array( 'authUrl' => Api_Client::authorization_url( (int) $user->id ) ) );
	}

	public static function health(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();
		wp_send_json_success( Store_Status::health_payload() );
	}

	public static function stats(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();
		wp_send_json_success( Store_Status::stats() );
	}

	public static function pending_ids(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();
		wp_send_json_success( [ 'ids' => Store_Status::pending_product_ids() ] );
	}

	public static function logs(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();
		wp_send_json_success( [ 'lines' => Logger::recent( 120 ) ] );
	}

	public static function logout(): void {
		check_admin_referer( 'vio_logout' );
		self::guard();

		// Tear down the backend connection too, while the key is still valid:
		// resolve this store's credential + ecom-user ids, then delete it.
		// Best-effort — the local cleanup runs regardless, so disconnect always
		// succeeds locally.
		$conn = Api_Client::find_woo_connection();
		if ( $conn ) {
			Api_Client::delete_api_credential( $conn['credential_id'], $conn['ecom_user_id'] );
			Logger::info( '[logout] backend credential ' . $conn['credential_id'] . ' deleted (ecomUser ' . $conn['ecom_user_id'] . ')' );
		}

		update_option( Plugin::OPT_API_KEY, '' );
		update_option( Plugin::OPT_CURRENCY, '' );
		Plugin::cleanup();

		Logger::info( '[logout] store disconnected from Vio' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . Config_Page::MENU_SLUG . '&vio_disconnected=1' ) );
		exit;
	}
}
