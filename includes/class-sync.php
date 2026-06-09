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
	 * Batch create/update (manual export).
	 *
	 * @param int[]  $post_ids
	 * @param string $user_api_key
	 */
	public static function push_products( array $post_ids, string $user_api_key ): void {
		$batched = [];
		foreach ( $post_ids as $post_id ) {
			$batched[] = [ 'product' => Product_Mapper::to_dto( $post_id ) ];
		}

		$result = Api_Client::create_products( [ 'products' => $batched ] );

		if ( ! is_wp_error( $result ) && isset( $result->messageId ) ) {
			foreach ( $post_ids as $post_id ) {
				update_post_meta( $post_id, Plugin::META_SQS_ID, $result->messageId );
				update_post_meta( $post_id, Plugin::META_APIKEY, $user_api_key );
			}
			Logger::info( '[push_products] batch sent, messageId ' . $result->messageId );
		} else {
			Logger::error( '[push_products] error creating products: ' . implode( ',', $post_ids ) );
		}
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
	 * Delete the Vio product linked to a post and clear its meta.
	 */
	public static function delete_by_post( int $post_id, string $user_api_key, ?string $delete_type = null ): void {
		$remote_id = Product_Mapper::get_remote_product_id( $user_api_key, $post_id );
		$sqs_id    = get_post_meta( $post_id, Plugin::META_SQS_ID, true );

		if ( $remote_id ) {
			if ( 'trash' === $delete_type ) {
				Api_Client::delete_product( $remote_id );
			}
			self::clear_product_meta( $post_id );
			Logger::info( '[delete_by_post] product ' . $remote_id . ' deleted (post ' . $post_id . ')' );
		} elseif ( $sqs_id ) {
			self::clear_product_meta( $post_id );
		}
	}

	private static function clear_product_meta( int $post_id ): void {
		foreach ( [ Plugin::META_PRODUCT_ID, Plugin::META_SQS_ID, Plugin::META_APIKEY, Plugin::META_UID ] as $meta ) {
			update_post_meta( $post_id, $meta, '' );
		}
	}
}
