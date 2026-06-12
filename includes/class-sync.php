<?php
/**
 * Sync service: business logic to push, update and delete products in Vio.
 * Used by both the AJAX actions and the auto-sync hooks.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Sync {

	/**
	 * Batch export — queues the products in Vio (manual "Vio Sync").
	 *
	 * @param int[]  $post_ids
	 * @param string $user_api_key
	 * @return bool True if the batch was accepted by the API.
	 */
	public static function push_products( array $post_ids, string $user_api_key ): bool {
		$batched = [];
		foreach ( $post_ids as $post_id ) {
			$batched[] = [ 'product' => Product_Mapper::to_dto( $post_id ) ];
		}

		$result = Api_Client::create_products( [ 'products' => $batched ] );

		if ( is_wp_error( $result ) || ! isset( $result->messageId ) ) {
			Logger::error( '[push_products] error creating products: ' . implode( ',', $post_ids ) );
			return false;
		}

		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, Plugin::META_SQS_ID, $result->messageId );
			update_post_meta( $post_id, Plugin::META_APIKEY, $user_api_key );
		}
		Logger::info( '[push_products] batch queued, messageId ' . $result->messageId );
		return true;
	}

	/**
	 * Update a product that already exists in Vio (auto-sync on save).
	 */
	public static function update_product( int $post_id, string $user_api_key ): void {
		// If the product originated in Vio, do not re-export it.
		if ( get_post_meta( $post_id, Plugin::META_ORIGIN, true ) ) {
			return;
		}

		$remote_id = Product_Mapper::get_remote_product_id( $user_api_key, $post_id );
		if ( ! $remote_id ) {
			return;
		}

		$current = Product_Mapper::to_dto( $post_id );
		$remote  = Api_Client::get_product( $remote_id );
		if ( is_wp_error( $remote ) ) {
			return;
		}

		$changes = Product_Mapper::diff( $current, $remote );

		// Inject the Vio variant ids into the variants being updated.
		if ( isset( $changes['variants'] ) && $remote ) {
			$remote_variants = isset( $remote->variants ) ? (array) $remote->variants : [];
			foreach ( $changes['variants'] as &$variant ) {
				foreach ( $remote_variants as $remote_variant ) {
					$remote_variant = (array) $remote_variant;
					if ( ( $variant['originId'] ?? null ) == ( $remote_variant['originId'] ?? null ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons
						$variant['id'] = $remote_variant['id'] ?? null;
					}
				}
			}
			unset( $variant );
		}

		if ( empty( $changes ) ) {
			Logger::info( '[update_product] no changes for ' . $remote_id );
			return;
		}

		$updated = Api_Client::update_product( $remote_id, $changes );
		if ( is_wp_error( $updated ) ) {
			Logger::error( '[update_product] error updating ' . $remote_id );
		} else {
			Logger::info( '[update_product] product ' . $remote_id . ' updated' );
		}
	}

	/**
	 * Unlink a product from Vio: delete the remote product (when its id is known)
	 * and clear the local sync meta. Works for "Sent"-only products too.
	 *
	 * @param bool $delete_remote Also delete the product in Vio when its id is known.
	 */
	public static function delete_by_post( int $post_id, string $user_api_key, bool $delete_remote = true ): void {
		$remote_id = Product_Mapper::get_remote_product_id( $user_api_key, $post_id );
		$sqs_id    = get_post_meta( $post_id, Plugin::META_SQS_ID, true );

		// Not linked to Vio at all → nothing to do.
		if ( ! $remote_id && '' === (string) $sqs_id ) {
			return;
		}

		if ( $delete_remote && $remote_id ) {
			Api_Client::delete_product( $remote_id );
			Logger::info( '[delete_by_post] remote product ' . $remote_id . ' deleted (post ' . $post_id . ')' );
		}

		// Always clear the local sync state so the product is fully unlinked.
		self::clear_product_meta( $post_id );
	}

	/**
	 * Delete every synced product in Vio (used by disconnect when the user opts
	 * in). The backend soft-deletes them, so existing references don't break.
	 * Batched to keep the request URL sane on large catalogues; local meta is
	 * cleared separately by unlink_all_products().
	 *
	 * @return int Number of remote products deleted.
	 */
	public static function delete_all_remote( string $user_api_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) AND meta_value <> ''",
				Plugin::META_PRODUCT_ID,
				Plugin::META_LEGACY_PRODUCT_ID
			)
		);

		$remote_ids = array();
		foreach ( $post_ids as $post_id ) {
			$remote_id = Product_Mapper::get_remote_product_id( $user_api_key, (int) $post_id );
			if ( $remote_id ) {
				$remote_ids[] = $remote_id;
			}
		}
		$remote_ids = array_values( array_unique( $remote_ids ) );

		foreach ( array_chunk( $remote_ids, 50 ) as $batch ) {
			Api_Client::delete_products( $batch );
		}

		if ( $remote_ids ) {
			Logger::info( '[delete_all_remote] deleted ' . count( $remote_ids ) . ' product(s) in Vio' );
		}
		return count( $remote_ids );
	}

	/** Per-product sync meta the plugin writes; cleared on unlink/disconnect. */
	private static function sync_meta_keys(): array {
		return [ Plugin::META_PRODUCT_ID, Plugin::META_LEGACY_PRODUCT_ID, Plugin::META_SQS_ID, Plugin::META_APIKEY, Plugin::META_UID ];
	}

	private static function clear_product_meta( int $post_id ): void {
		foreach ( self::sync_meta_keys() as $meta ) {
			update_post_meta( $post_id, $meta, '' );
		}
	}

	/**
	 * Store-wide unlink: drop the plugin's per-product sync meta from every
	 * product. Called on disconnect so the store stops reporting products as
	 * "Synced" against a backend it is no longer connected to, and a later
	 * reconnect starts from a clean slate. META_ORIGIN is intentionally kept —
	 * it marks products that came *from* Vio, not the sync link.
	 *
	 * @return int Number of products whose meta was cleared.
	 */
	public static function unlink_all_products(): int {
		global $wpdb;

		$keys         = self::sync_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", $keys )
		);

		if ( empty( $post_ids ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", $keys )
		);

		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}

		Logger::info( '[unlink_all] cleared sync meta from ' . count( $post_ids ) . ' product(s)' );
		return count( $post_ids );
	}
}
