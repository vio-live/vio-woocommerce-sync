<?php
/**
 * Acciones AJAX. Todas requieren usuario autenticado con capability y nonce
 * (no se registran variantes `nopriv`).
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
	}

	/** Verifica capability; corta con 403 si no la tiene. */
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
		Sync::push_products( $post_ids, Api_Client::api_key() );
		wp_send_json_success();
	}

	public static function delete(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();

		$post_ids = self::post_ids();
		$api_key  = Api_Client::api_key();
		if ( empty( $post_ids ) || '' === $api_key ) {
			wp_send_json_error( [ 'message' => 'no_ids' ] );
		}

		$remote_ids = [];
		foreach ( $post_ids as $post_id ) {
			$remote_id = Product_Mapper::get_remote_product_id( $api_key, $post_id );
			if ( $remote_id ) {
				$remote_ids[] = $remote_id;
				Sync::delete_by_post( $post_id, $api_key );
			}
		}

		if ( $remote_ids ) {
			Api_Client::delete_products( $remote_ids );
		}
		wp_send_json_success();
	}

	public static function finish_sync(): void {
		check_ajax_referer( 'vio_sync', 'nonce' );
		self::guard();

		Logger::info( '[finish_sync] notificando fin de primer sync' );
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

	public static function logout(): void {
		check_admin_referer( 'vio_logout' );
		self::guard();

		update_option( Plugin::OPT_API_KEY, '' );
		update_option( Plugin::OPT_CURRENCY, '' );
		Plugin::cleanup();

		Logger::info( '[logout] tienda desconectada de Vio' );
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=' . Settings::TAB_ID ) );
		exit;
	}
}
