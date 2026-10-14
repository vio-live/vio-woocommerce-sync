<?php
/**
 * Product-list integration: status column, bulk actions and auto-sync on
 * save / trash / delete.
 *
 * @package VioSync
 */

declare( strict_types=1 );

namespace VioSync;

defined( 'ABSPATH' ) || exit;

final class Products_Table {

	public const COLUMN = 'col_viosync_sync';

	/**
	 * Runs after WooCommerce's deferred product sync (shutdown, priority 10),
	 * which saves a variable parent once more with its final variation data.
	 */
	private const FLUSH_PRIORITY = 20;

	/** @var array<int,true> Products saved during this request, synced once on shutdown. */
	private static $pending = [];

	public static function init(): void {
		add_filter( 'manage_edit-product_columns', [ self::class, 'add_column' ], 20 );
		add_action( 'manage_product_posts_custom_column', [ self::class, 'render_column' ], 10, 2 );
		add_filter( 'bulk_actions-edit-product', [ self::class, 'add_bulk_actions' ] );
		add_filter( 'woocommerce_max_webhook_delivery_failures', [ self::class, 'raise_webhook_failure_limit' ] );

		add_action( 'woocommerce_update_product', [ self::class, 'on_save_product' ] );
		add_action( 'woocommerce_new_product', [ self::class, 'on_save_product' ] );
		add_action( 'woocommerce_update_product_variation', [ self::class, 'on_save_variation' ], 10, 2 );
		add_action( 'woocommerce_new_product_variation', [ self::class, 'on_save_variation' ], 10, 2 );
		add_action( 'shutdown', [ self::class, 'flush_pending' ], self::FLUSH_PRIORITY );
		add_action( 'wp_trash_post', [ self::class, 'on_trash_post' ] );
		add_action( 'before_delete_post', [ self::class, 'on_before_delete' ] );

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check (which list table we are on), no state change.
		if ( 'product' !== $post_type ) {
			return;
		}

		// Version by file mtime so edits bust the browser cache (matches Config_Page).
		$css_ver = (string) ( @filemtime( VIOSYNC_DIR . 'assets/css/admin.css' ) ?: VIOSYNC_VERSION ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$js_ver  = (string) ( @filemtime( VIOSYNC_DIR . 'assets/js/products.js' ) ?: VIOSYNC_VERSION ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		wp_enqueue_style( 'viosync-admin', VIOSYNC_URL . 'assets/css/admin.css', [], $css_ver );
		wp_enqueue_script( 'viosync-products', VIOSYNC_URL . 'assets/js/products.js', [ 'jquery' ], $js_ver, true );
		wp_localize_script(
			'viosync-products',
			'viosyncData',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'viosync_sync' ),
			]
		);
	}

	public static function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = esc_html__( 'Vio', 'vio-sync-for-woocommerce' );
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
			$synced_title = sprintf(
				/* translators: %s: the product's Vio ID. */
				__( 'Synced with Vio · product #%s', 'vio-sync-for-woocommerce' ),
				(string) $product_id
			);
			printf( '<span class="vio-synced" title="%s">', esc_attr( $synced_title ) );
			// Self-contained checkmark — static markup, no dynamic data.
			echo '<svg class="vio-synced__check" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M4 10.5l3.5 3.5L16 5.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
			echo '<span class="vio-synced__label">' . esc_html__( 'Synced', 'vio-sync-for-woocommerce' ) . '</span>';
			echo '</span>';
		} elseif ( $sqs_id ) {
			echo '<span class="vio-syncing" title="'
				. esc_attr__( 'Sent to Vio — waiting for it to finish processing the product.', 'vio-sync-for-woocommerce' )
				. '">' . esc_html__( 'Sent', 'vio-sync-for-woocommerce' ) . '</span>';
		}
	}

	public static function add_bulk_actions( array $actions ): array {
		$actions['viosync_sync']   = __( 'Sync', 'vio-sync-for-woocommerce' );
		$actions['viosync_unsync'] = __( 'Unsync', 'vio-sync-for-woocommerce' );
		$actions['viosync_delete'] = __( 'Delete from Vio', 'vio-sync-for-woocommerce' );
		return $actions;
	}

	public static function raise_webhook_failure_limit(): int {
		return PHP_INT_MAX;
	}

	/**
	 * Auto-sync on product create/update: queue it, sync once at the end of the request.
	 *
	 * Syncing right away sent stale data: when variations are saved, WooCommerce saves the
	 * parent before them (default attributes) and again after them (deferred sync), so the
	 * first save still carries the old variation data.
	 *
	 * @param int|string $post_id
	 */
	public static function on_save_product( $post_id ): void {
		if ( ! Api_Client::has_api_key() ) {
			return;
		}
		self::$pending[ (int) $post_id ] = true;
	}

	/**
	 * A variation change is a change of its parent product in Vio.
	 *
	 * @param int|string       $variation_id
	 * @param \WC_Product|null $variation
	 */
	public static function on_save_variation( $variation_id, $variation = null ): void {
		$parent_id = $variation instanceof \WC_Product
			? $variation->get_parent_id()
			: wp_get_post_parent_id( (int) $variation_id );
		if ( $parent_id ) {
			self::on_save_product( $parent_id );
		}
	}

	/** Sync every product saved during this request, once, with its final data. */
	public static function flush_pending(): void {
		$post_ids      = array_keys( self::$pending );
		self::$pending = [];

		$api_key = Api_Client::api_key();
		if ( '' === $api_key ) {
			return;
		}

		foreach ( $post_ids as $post_id ) {
			// Deleted or trashed during the request: the trash/delete handlers own it.
			if ( 'product' !== get_post_type( $post_id ) || 'trash' === get_post_status( $post_id ) ) {
				continue;
			}
			Sync::update_product( $post_id, $api_key );
		}
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

		// Trashing a synced product unlinks it from Vio (delete remote + clear meta).
		// Attachments are kept here so the product can be restored from the trash.
		Sync::delete_by_post( $post_id, $api_key, true );
	}

	/**
	 * @param int|string $post_id
	 */
	public static function on_before_delete( $post_id ): void {
		$post_id = (int) $post_id;

		// Products imported FROM Vio carry Vio-owned images: drop them on permanent delete.
		if ( get_post_meta( $post_id, Plugin::META_ORIGIN, true ) ) {
			self::delete_product_images( $post_id );
		}

		// Unlink from Vio if still linked (e.g. permanently deleted without trashing first).
		$api_key = Api_Client::api_key();
		if ( '' !== $api_key ) {
			Sync::delete_by_post( $post_id, $api_key, true );
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
