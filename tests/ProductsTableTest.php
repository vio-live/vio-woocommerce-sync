<?php
/**
 * Integration tests for the auto-sync on save (Products_Table).
 *
 * When variations are saved from the admin, WooCommerce saves the parent
 * before them and again after them. The plugin used to sync on the first save
 * (old variation data) and a 5 s lock dropped the later, correct ones, so Vio
 * got nothing, or the previous change.
 *
 * Every HTTP call to the Vio API is intercepted here and answered with a fake
 * "remote" product: nothing reaches the network. The API key is a fake value
 * for the length of each test and the stored one is restored afterwards.
 *
 * @package VioSync\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use VioSync\Plugin;
use VioSync\Product_Mapper;
use VioSync\Products_Table;

final class ProductsTableTest extends TestCase {

	/** @var int[] Post ids to delete in tearDown (variations first). */
	private $created = array();

	/** @var array<int,array{method:string,url:string,body:?array}> Intercepted Vio calls. */
	private $requests = array();

	/** @var array<string,array> Fake Vio products, by remote id. */
	private $remote = array();

	/** @var mixed The stored API key, restored in tearDown. */
	private $saved_api_key;

	/** @var callable */
	private $intercept;

	protected function setUp(): void {
		$this->saved_api_key = get_option( Plugin::OPT_API_KEY, null );
		update_option( Plugin::OPT_API_KEY, 'test-api-key' );

		$this->intercept = function ( $pre, $args, $url ) {
			return $this->fake_vio( $args, (string) $url );
		};
		add_filter( 'pre_http_request', $this->intercept, 1, 3 );

		// Drain anything queued before this test, then start from a clean log.
		Products_Table::flush_pending();
		$this->requests = array();
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->created ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
			}
		}
		$this->created = array();
		Products_Table::flush_pending();

		remove_filter( 'pre_http_request', $this->intercept, 1 );
		if ( null === $this->saved_api_key ) {
			delete_option( Plugin::OPT_API_KEY );
		} else {
			update_option( Plugin::OPT_API_KEY, $this->saved_api_key );
		}
	}

	/** The admin flow: parent saved, then the variation, then the parent again (deferred sync). */
	public function test_variation_saved_from_the_admin_syncs_the_final_price(): void {
		[ $parent_id, $cheapest ] = $this->create_linked_variable();

		wc_get_product( $parent_id )->save();

		$variation = wc_get_product( $cheapest );
		$variation->set_regular_price( '80' );
		$variation->save();

		WC_Product_Variable::sync( $parent_id );
		Products_Table::flush_pending();

		$puts = $this->requests_of( 'PUT' );
		$this->assertCount( 1, $puts );
		$this->assertSame( '80', $puts[0]['body']['price']['amount'] );
		$this->assertSame( '80', $this->variant_in( $puts[0]['body'], $cheapest )['price'] );
	}

	/** A variation saved on its own (REST API, bulk edit…) syncs its parent. */
	public function test_variation_saved_alone_syncs_its_parent(): void {
		[ $parent_id, $cheapest ] = $this->create_linked_variable();

		$variation = wc_get_product( $cheapest );
		$variation->set_regular_price( '90' );
		$variation->save();
		Products_Table::flush_pending();

		$puts = $this->requests_of( 'PUT' );
		$this->assertCount( 1, $puts );
		$this->assertStringEndsWith( '/test-' . $parent_id, $puts[0]['url'] );
		$this->assertSame( '90', $puts[0]['body']['price']['amount'] );
	}

	public function test_several_saves_in_one_request_sync_once(): void {
		[ $parent_id ] = $this->create_linked_variable();

		for ( $i = 0; $i < 3; $i++ ) {
			wc_get_product( $parent_id )->save();
		}
		Products_Table::flush_pending();

		$this->assertCount( 1, $this->requests_of( 'GET' ) );
		$this->assertCount( 0, $this->requests_of( 'PUT' ), 'nothing changed, nothing to send' );
	}

	public function test_a_product_trashed_in_the_same_request_is_not_updated(): void {
		[ $parent_id ] = $this->create_linked_variable();

		wc_get_product( $parent_id )->save();
		wp_trash_post( $parent_id );
		Products_Table::flush_pending();

		$this->assertCount( 0, $this->requests_of( 'PUT' ) );
		$this->assertCount( 0, $this->requests_of( 'GET' ) );
	}

	/**
	 * A published variable product (sizes S/M/L at 100/150/200) linked to a fake
	 * Vio product that holds the same data.
	 *
	 * @return array{0:int,1:int} Parent id and the id of its cheapest variation.
	 */
	private function create_linked_variable(): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'S', 'M', 'L' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Vio test linked variable product' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent_id       = $parent->save();
		$this->created[] = $parent_id;

		$ids = array();
		foreach ( array( 'S' => '100', 'M' => '150', 'L' => '200' ) as $size => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( $price );
			$variation->set_status( 'publish' );
			$ids[]           = $variation->save();
			$this->created[] = end( $ids );
		}
		WC_Product_Variable::sync( $parent_id );
		wc_delete_product_transients( $parent_id );

		update_post_meta( $parent_id, Plugin::META_PRODUCT_ID, 'test-' . $parent_id );
		$this->remote[ 'test-' . $parent_id ] = $this->as_remote( Product_Mapper::to_dto( $parent_id ) );

		// Creating it queued a sync: run it now so each test starts from a clean log.
		Products_Table::flush_pending();
		$this->requests = array();

		return array( $parent_id, $ids[0] );
	}

	/** What Vio returns for a product: the DTO plus `originalPrice` on the product and its variants. */
	private function as_remote( array $dto ): array {
		$remote                  = $dto;
		$remote['originalPrice'] = $dto['price'];
		foreach ( $remote['variants'] as &$variant ) {
			$variant['originalPrice'] = array(
				'amount'    => $variant['price'],
				'compareAt' => $variant['priceCompareAt'],
			);
		}
		unset( $variant );
		return $remote;
	}

	/** @return array|\WP_Error */
	private function fake_vio( array $args, string $url ) {
		$method = strtoupper( $args['method'] ?? 'GET' );
		$body   = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;

		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'body'   => $body,
		);

		$ok = static function ( $data ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $data ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		if ( preg_match( '#/api/products/([^/?]+)$#', $url, $m ) && isset( $this->remote[ $m[1] ] ) ) {
			if ( 'GET' === $method ) {
				return $ok( $this->remote[ $m[1] ] );
			}
			if ( 'PUT' === $method ) {
				return $ok( array() );
			}
		}
		return new WP_Error( 'viosync_test_offline', 'Vio API calls are intercepted in tests' );
	}

	private function requests_of( string $method ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $method ) {
					return $method === $request['method'];
				}
			)
		);
	}

	private function variant_in( array $body, int $origin_id ): array {
		foreach ( $body['variants'] ?? array() as $variant ) {
			if ( (string) $variant['originId'] === (string) $origin_id ) {
				return $variant;
			}
		}
		$this->fail( "variant {$origin_id} not in the payload" );
	}
}
