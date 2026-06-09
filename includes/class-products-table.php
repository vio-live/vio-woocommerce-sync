<?php
/**
 * Product-list integration: status column, bulk actions and auto-sync on
 * save / trash / delete.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Products_Table {

	public const COLUMN = 'col_vio_sync';

	public static function init(): void {
		add_filter( 'manage_edit-product_columns', [ self::class, 'add_column' ], 20 );
		add_action( 'manage_product_posts_custom_column', [ self::class, 'render_column' ], 10, 2 );
		add_filter( 'bulk_actions-edit-product', [ self::class, 'add_bulk_actions' ] );
		add_filter( 'woocommerce_max_webhook_delivery_failures', [ self::class, 'raise_webhook_failure_limit' ] );

		add_action( 'woocommerce_update_product', [ self::class, 'on_save_product' ] );
		add_action( 'woocommerce_new_product', [ self::class, 'on_save_product' ] );
		add_action( 'wp_trash_post', [ self::class, 'on_trash_post' ] );
		add_action( 'before_delete_post', [ self::class, 'on_before_delete' ] );

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( 'product' !== $post_type ) {
			return;
		}

		wp_enqueue_style( 'vio-wc-sync-admin', VIO_WC_SYNC_URL . 'assets/css/admin.css', [], VIO_WC_SYNC_VERSION );
		wp_enqueue_script( 'vio-wc-sync-products', VIO_WC_SYNC_URL . 'assets/js/products.js', [ 'jquery' ], VIO_WC_SYNC_VERSION, true );
		wp_localize_script(
			'vio-wc-sync-products',
			'vioWcSync',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vio_sync' ),
			]
		);
	}

	public static function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = esc_html__( 'Vio', 'vio-woocommerce-sync' );
		return $columns;
	}

	/**
	 * @param string     $column
	 * @param int|string $post_id
	 */
	public static function render_column( string $column, $post_id ): void {
		if ( self::COLUMN !== $column || ! Api_Client::has_api_key() ) {
			return;
		}

		$post_id    = (int) $post_id;
		$product_id = Product_Mapper::get_remote_product_id( Api_Client::api_key(), $post_id );
		$sqs_id     = get_post_meta( $post_id, Plugin::META_SQS_ID, true );

		if ( $product_id ) {
			printf(
				'<img class="vio-icon" src="%s" alt="%s" width="20" />',
				esc_url( VIO_WC_SYNC_URL . 'assets/img/icon.svg' ),
				esc_attr__( 'Synced with Vio', 'vio-woocommerce-sync' )
			);
		} elseif ( $sqs_id ) {
			echo '<span class="vio-syncing">' . esc_html__( 'Syncing…', 'vio-woocommerce-sync' ) . '</span>';
		}
	}

	public static function add_bulk_actions( array $actions ): array {
		$actions['vio_sync']   = __( 'Vio Sync', 'vio-woocommerce-sync' );
		$actions['vio_delete'] = __( 'Delete from Vio', 'vio-woocommerce-sync' );
		return $actions;
	}

	public static function raise_webhook_failure_limit(): int {
		return PHP_INT_MAX;
	}

	/**
	 * Auto-sync on product create/update.
	 *
	 * @param int|string $post_id
	 */
	public static function on_save_product( $post_id ): void {
		$post_id = (int) $post_id;
		$api_key = Api_Client::api_key();
		if ( '' === $api_key ) {
			return;
		}

		// Avoid duplicate triggers within the same request.
		$transient = 'vio_updating_' . $post_id;
		if ( false !== get_transient( $transient ) ) {
			return;
		}
		set_transient( $transient, $post_id, 5 );

		Sync::update_product( $post_id, $api_key );
	}

	/**
	 * @param int|string $post_id
	 */
	public static function on_trash_post( $post_id ): void {
		$post_id = (int) $post_id;
		$api_key = Api_Client::api_key();
		if ( '' === $api_key ) {
			return;
		}

		if ( get_post_meta( $post_id, Plugin::META_ORIGIN, true ) ) {
			self::delete_product_images( $post_id );
		}
		Sync::delete_by_post( $post_id, $api_key, 'trash' );
	}

	/**
	 * @param int|string $post_id
	 */
	public static function on_before_delete( $post_id ): void {
		if ( get_post_meta( (int) $post_id, Plugin::META_ORIGIN, true ) ) {
			self::delete_product_images( (int) $post_id );
		}
	}

	private static function delete_product_images( int $post_id ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		$ids = array_merge(
			array_filter( [ $product->get_image_id() ] ),
			$product->get_gallery_image_ids()
		);
		foreach ( $ids as $id ) {
			if ( $id ) {
				wp_delete_attachment( (int) $id );
			}
		}
	}
}
