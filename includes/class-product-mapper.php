<?php
/**
 * Maps WooCommerce products to the Vio DTO and computes the changes (diff)
 * between the WooCommerce state and the Vio state before updating.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Product_Mapper {

	private const MAX_OPTIONS     = 3;
	private const FLOAT_TOLERANCE = 0.01;

	/**
	 * Force https on an image URL.
	 */
	public static function force_secure_image( string $image_url ): string {
		if ( 'https' !== wp_parse_url( $image_url, PHP_URL_SCHEME ) ) {
			return str_replace( 'http://', 'https://', $image_url );
		}
		return $image_url;
	}

	/**
	 * Return the Vio product id linked to a WooCommerce post, for a given API key.
	 */
	public static function get_remote_product_id( string $user_api_key, int $post_id ): ?string {
		$field = get_post_meta( $post_id, Plugin::META_PRODUCT_ID, true );

		$decoded = json_decode( (string) $field, true );
		if ( is_array( $decoded ) ) {
			$key = array_search( $user_api_key, array_column( $decoded, 'idusr' ), true );
			if ( false !== $key ) {
				return (string) $decoded[ $key ]['idprod'];
			}
			return null;
		}

		return is_string( $field ) && '' !== $field ? $field : null;
	}

	/**
	 * Build the DTO of a WooCommerce product to send to Vio.
	 *
	 * @return array<string,mixed>
	 */
	public static function to_dto( int $post_id ): array {
		$product  = wc_get_product( $post_id );
		$currency = (string) get_option( Plugin::OPT_CURRENCY );

		$regular_price = $product->get_regular_price();
		$sale_price    = $product->get_sale_price();
		$price_to_use  = $regular_price;
		$compare_at    = '';
		if ( $sale_price && $sale_price < $regular_price ) {
			$price_to_use = $sale_price;
			$compare_at   = $regular_price;
		}

		$images = self::collect_images( $product );

		[ $variants, $options, $options_enabled, $stock ] = self::collect_variants( $product );

		$dto = [
			'title'          => $product->get_title(),
			'description'    => $product->get_description(),
			'price'          => [
				'amount'       => $price_to_use,
				'compareAt'    => $compare_at,
				'currencyCode' => $currency,
			],
			'origin'         => 'WOOCOMMERCE',
			'originId'       => $post_id,
			'images'         => $images,
			'quantity'       => $stock,
			'barcode'        => '',
			'sku'            => $product->get_sku(),
			'optionsEnabled' => $options_enabled,
			'options'        => $options,
			'variants'       => $variants,
			'currency'       => $currency,
			'from'           => 'WOOCOMMERCE',
		];

		if ( '' !== $product->get_weight() ) {
			$dto['weight'] = $product->get_weight();
		}
		if ( '' !== $product->get_width() ) {
			$dto['width'] = $product->get_width();
		}
		if ( '' !== $product->get_height() ) {
			$dto['height'] = $product->get_height();
		}
		if ( '' !== $product->get_length() ) {
			$dto['depth'] = $product->get_length();
		}

		return $dto;
	}

	/**
	 * @return array<int,array{order:int,image:string}>
	 */
	private static function collect_images( \WC_Product $product ): array {
		$images = [];
		$order  = 1;

		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $url ) {
				$images[] = [
					'order' => 0,
					'image' => self::force_secure_image( $url ),
				];
			}
		}

		foreach ( $product->get_gallery_image_ids() as $attachment_id ) {
			if ( ! $attachment_id ) {
				continue;
			}
			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
			if ( $url ) {
				$images[] = [
					'order' => $order,
					'image' => self::force_secure_image( $url ),
				];
				++$order;
			}
		}

		return $images;
	}

	/**
	 * @return array{0:array,1:array,2:bool,3:int} [variants, options, options_enabled, stock]
	 */
	private static function collect_variants( \WC_Product $product ): array {
		$variants        = [];
		$options         = [];
		$options_enabled = false;
		$stock           = (int) $product->get_stock_quantity();

		$attributes = $product->get_attributes();
		if ( ! $attributes || ! $product->is_type( 'variable' ) ) {
			return [ $variants, $options, $options_enabled, $stock ];
		}

		$available = $product->get_available_variations();
		if ( empty( $available ) ) {
			return [ $variants, $options, $options_enabled, $stock ];
		}

		$options_enabled = true;
		$stock           = 0;
		$count           = 1;

		foreach ( $attributes as $attribute ) {
			$name        = $attribute['name'];
			$option_list = $attribute['options'];

			if ( $attribute->is_taxonomy() ) {
				$taxonomy    = $attribute->get_taxonomy_object();
				$name        = $taxonomy->attribute_label;
				$option_list = [];
				$terms       = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'all' ] );
				foreach ( $terms as $term ) {
					$option_list[] = $term->name;
				}
			}

			$options[] = [
				'name'   => $name,
				'order'  => $count,
				'values' => implode( ',', $option_list ),
			];

			if ( ++$count > self::MAX_OPTIONS ) {
				break;
			}
		}

		foreach ( $available as $variation_item ) {
			$variation_id = $variation_item['variation_id'];
			$variation    = new \WC_Product_Variation( $variation_id );
			$stock       += (int) $variation->get_stock_quantity();

			$variation_images = [];
			$variation_img_id = $variation->get_image_id();
			if ( $variation_img_id ) {
				$url = wp_get_attachment_image_url( $variation_img_id, 'full' );
				if ( $url ) {
					$variation_images[] = [
						'image' => self::force_secure_image( $url ),
						'order' => 0,
					];
				}
			}

			$v_regular = $variation->get_regular_price();
			$v_sale    = $variation->get_sale_price();
			$v_price   = $v_regular;
			$v_compare = '';
			if ( $v_sale && $v_sale < $v_regular ) {
				$v_price   = $v_sale;
				$v_compare = $v_regular;
			}

			$variants[] = [
				'sku'            => $variation->get_sku(),
				'price'          => $v_price,
				'priceCompareAt' => $v_compare,
				'quantity'       => (int) $variation->get_stock_quantity(),
				'title'          => implode( '-', $variation->get_attributes() ),
				'originId'       => $variation_id,
				'images'         => $variation_images,
			];
		}

		return [ $variants, $options, $options_enabled, $stock ];
	}

	// --- Diffing ----------------------------------------------------------

	/**
	 * Compute the fields that changed between the WooCommerce state and Vio's.
	 *
	 * @param array $current Current state (WooCommerce).
	 * @param mixed $initial Vio state (object/array).
	 * @return array<string,mixed>
	 */
	public static function diff( array $current, $initial ): array {
		$changes = [];
		$initial = is_object( $initial ) ? get_object_vars( $initial ) : (array) $initial;

		foreach ( $current as $key => $value ) {
			if ( ! array_key_exists( $key, $initial ) ) {
				$changes[ $key ] = $value;
				continue;
			}

			$initial_value = $initial[ $key ];

			switch ( $key ) {
				case 'variants':
					$variant_changes = self::compare_variants( $value, $initial_value );
					if ( ! empty( $variant_changes ) ) {
						$changes[ $key ] = $variant_changes;
					}
					break;

				case 'images':
					$image_changes = self::compare_images( $value, $initial_value );
					if ( ! empty( $image_changes ) ) {
						$changes[ $key ] = $image_changes;
					}
					break;

				case 'options':
					if ( ! self::options_equal( $value, $initial_value ) ) {
						$changes[ $key ] = $value;
					}
					break;

				case 'price':
					$price_change = self::main_price_changed( $value, $initial['originalPrice'] ?? null );
					if ( $price_change['amount'] || $price_change['compareAt'] ) {
						$update = [ 'currencyCode' => $value['currencyCode'] ];
						if ( $price_change['amount'] ) {
							$update['amount'] = $value['amount'];
						}
						if ( $price_change['compareAt'] ) {
							$update['compareAt'] = $value['compareAt'];
						}
						$changes[ $key ] = $update;
					}
					break;

				default:
					if ( is_array( $value ) && is_array( $initial_value ) ) {
						$nested = self::diff( $value, $initial_value );
						if ( ! empty( $nested ) ) {
							$changes[ $key ] = $nested;
						}
					} elseif ( $value !== $initial_value ) {
						$changes[ $key ] = $value;
					}
			}
		}

		// Normalize the image key in variants (image → url).
		if ( isset( $changes['variants'] ) ) {
			foreach ( $changes['variants'] as &$variant ) {
				if ( ! empty( $variant['images'] ) && is_array( $variant['images'] ) ) {
					foreach ( $variant['images'] as &$image ) {
						if ( isset( $image['image'] ) ) {
							$image['url'] = $image['image'];
						}
					}
					unset( $image );
				}
			}
			unset( $variant );
		}

		foreach ( [ 'originId', 'currency', 'from' ] as $unnecessary ) {
			unset( $changes[ $unnecessary ] );
		}

		return $changes;
	}

	private static function options_equal( $current, $initial ): bool {
		$current = is_object( $current ) ? get_object_vars( $current ) : $current;
		$initial = is_object( $initial ) ? get_object_vars( $initial ) : $initial;

		if ( count( $current ) !== count( $initial ) ) {
			return false;
		}

		foreach ( $current as $i => $option ) {
			$option = is_object( $option ) ? get_object_vars( $option ) : $option;
			if ( ! isset( $initial[ $i ] ) ) {
				return false;
			}
			$initial_option = is_object( $initial[ $i ] ) ? get_object_vars( $initial[ $i ] ) : $initial[ $i ];

			$current_values = is_array( $option['values'] ) ? implode( ',', $option['values'] ) : $option['values'];
			$initial_values = is_array( $initial_option['values'] ) ? implode( ',', $initial_option['values'] ) : $initial_option['values'];

			if ( $option['name'] !== $initial_option['name']
				|| $option['order'] !== $initial_option['order']
				|| $current_values !== $initial_values ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array{amount:bool,compareAt:bool}
	 */
	private static function main_price_changed( $current, $original ): array {
		$current  = is_object( $current ) ? get_object_vars( $current ) : $current;
		$original = is_object( $original ) ? get_object_vars( $original ) : $original;

		$currency_matches = isset( $current['currencyCode'], $original['currencyCode'] )
			&& $current['currencyCode'] === $original['currencyCode'];

		if ( ! $currency_matches ) {
			return [ 'amount' => false, 'compareAt' => false ];
		}

		return [
			'amount'    => isset( $current['amount'], $original['amount'] )
				&& abs( (float) $current['amount'] - (float) $original['amount'] ) > self::FLOAT_TOLERANCE,
			'compareAt' => isset( $current['compareAt'], $original['compareAt'] )
				&& abs( (float) $current['compareAt'] - (float) $original['compareAt'] ) > self::FLOAT_TOLERANCE,
		];
	}

	private static function compare_images( array $current, $initial ): array {
		$initial = (array) $initial;
		if ( count( $current ) !== count( $initial ) ) {
			return $current;
		}
		foreach ( $current as $i => $image ) {
			if ( ! isset( $initial[ $i ] ) || ! self::image_equal( $image, $initial[ $i ] ) ) {
				return $current;
			}
		}
		return [];
	}

	private static function image_equal( $a, $b ): bool {
		$a = is_object( $a ) ? get_object_vars( $a ) : $a;
		$b = is_object( $b ) ? get_object_vars( $b ) : $b;

		$url_a = $a['image'] ?? $a['url'] ?? '';
		$url_b = $b['image'] ?? $b['url'] ?? '';

		if ( '' === $url_a || '' === $url_b ) {
			return true;
		}
		return $url_a === $url_b;
	}

	private static function compare_variants( array $woo_variants, $vio_variants ): array {
		$vio_variants = (array) $vio_variants;

		foreach ( $woo_variants as $woo_variant ) {
			if ( ! empty( $woo_variant['images'] ) && is_array( $woo_variant['images'] ) ) {
				foreach ( $woo_variant['images'] as $idx => $image ) {
					if ( isset( $image['image'] ) ) {
						$woo_variant['images'][ $idx ]['url'] = $image['image'];
						unset( $woo_variant['images'][ $idx ]['image'] );
					}
				}
			}

			$vio_variant = self::find_variant_by_origin( $vio_variants, $woo_variant['originId'] ?? null );
			if ( $vio_variant && self::variant_changed( $woo_variant, $vio_variant ) ) {
				return $woo_variants;
			}
		}

		return [];
	}

	private static function find_variant_by_origin( array $variants, $origin_id ) {
		foreach ( $variants as $variant ) {
			$variant = is_object( $variant ) ? get_object_vars( $variant ) : $variant;
			if ( isset( $variant['originId'] ) && (string) $variant['originId'] === (string) $origin_id ) {
				return $variant;
			}
		}
		return null;
	}

	private static function variant_changed( $woo, $vio ): bool {
		$woo = is_object( $woo ) ? get_object_vars( $woo ) : $woo;
		$vio = is_object( $vio ) ? get_object_vars( $vio ) : $vio;

		if ( isset( $vio['originalPrice'] ) && is_object( $vio['originalPrice'] ) ) {
			$vio['originalPrice'] = get_object_vars( $vio['originalPrice'] );
		}
		if ( isset( $vio['images'][0] ) && is_object( $vio['images'][0] ) ) {
			$vio['images'][0] = get_object_vars( $vio['images'][0] );
		}

		$checks = [
			'Price'          => abs( (float) $woo['price'] - (float) ( $vio['originalPrice']['amount'] ?? 0 ) ) > self::FLOAT_TOLERANCE,
			'PriceCompareAt' => abs( (float) $woo['priceCompareAt'] - (float) ( $vio['originalPrice']['compareAt'] ?? 0 ) ) > self::FLOAT_TOLERANCE,
			'Quantity'       => $woo['quantity'] !== ( $vio['quantity'] ?? null ),
			'Title'          => $woo['title'] !== ( $vio['title'] ?? null ),
			'OriginId'       => (string) $woo['originId'] !== (string) ( $vio['originId'] ?? '' ),
			'Image'          => ! self::image_equal( $woo['images'][0] ?? [], $vio['images'][0] ?? [] ),
			'SKU'            => $woo['sku'] !== ( $vio['sku'] ?? null ),
		];

		$changed = array_keys( array_filter( $checks ) );
		if ( $changed ) {
			Logger::info( 'Variant changed due to: ' . implode( ', ', $changed ) );
			return true;
		}
		return false;
	}
}
