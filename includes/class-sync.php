<?php
/**
 * Servicio de sincronización: lógica de negocio para enviar, actualizar y
 * borrar productos en Vio. Lo usan tanto las acciones AJAX como el auto-sync.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Sync {

	/**
	 * Alta/actualización por lotes (export manual).
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
			Logger::info( '[push_products] lote enviado, messageId ' . $result->messageId );
		} else {
			Logger::error( '[push_products] error creando productos: ' . implode( ',', $post_ids ) );
		}
	}

	/**
	 * Actualiza un producto ya existente en Vio (auto-sync al guardar).
	 */
	public static function update_product( int $post_id, string $user_api_key ): void {
		// Si el producto se originó en Vio, no se reexporta.
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

		// Inyecta los ids de variante de Vio en las variantes a actualizar.
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
			Logger::info( '[update_product] sin cambios para ' . $remote_id );
			return;
		}

		$updated = Api_Client::update_product( $remote_id, $changes );
		if ( is_wp_error( $updated ) ) {
			Logger::error( '[update_product] error actualizando ' . $remote_id );
		} else {
			Logger::info( '[update_product] producto ' . $remote_id . ' actualizado' );
		}
	}

	/**
	 * Borra en Vio el producto asociado a un post y limpia su meta.
	 */
	public static function delete_by_post( int $post_id, string $user_api_key, ?string $delete_type = null ): void {
		$remote_id = Product_Mapper::get_remote_product_id( $user_api_key, $post_id );
		$sqs_id    = get_post_meta( $post_id, Plugin::META_SQS_ID, true );

		if ( $remote_id ) {
			if ( 'trash' === $delete_type ) {
				Api_Client::delete_product( $remote_id );
			}
			self::clear_product_meta( $post_id );
			Logger::info( '[delete_by_post] producto ' . $remote_id . ' borrado (post ' . $post_id . ')' );
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
