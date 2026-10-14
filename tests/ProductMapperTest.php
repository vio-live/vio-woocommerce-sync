<?php
/**
 * Integration tests for the product price sent to Vio.
 *
 * A variable product has no price of its own in WooCommerce (only its
 * variations do). Before this fix the mapper sent the parent's empty price,
 * which landed in Vio as 0.
 *
 * The products created here are never linked to Vio, so the plugin's
 * save/delete hooks return early and nothing reaches the Vio API.
 *
 * @package VioSync\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use VioSync\Product_Mapper;

final class ProductMapperTest extends TestCase {

	/** @var int[] Post ids to delete in tearDown (variations first). */
	private $created = array();

	protected function tearDown(): void {
		foreach ( array_reverse( $this->created ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
			}
		}
		$this->created = array();
	}

	/**
	 * Create a published variable product with one "Size" attribute and one
	 * variation per entry of $variations: [ option, regular, sale ].
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $variations
	 */
	private function create_variable( array $variations ): int {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array_column( $variations, 0 ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Vio test variable product' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent_id       = $parent->save();
		$this->created[] = $parent_id;

		foreach ( $variations as [ $option, $regular, $sale ] ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( 'size' => $option ) );
			$variation->set_regular_price( $regular );
			$variation->set_sale_price( $sale );
			$variation->set_status( 'publish' );
			$this->created[] = $variation->save();
		}

		WC_Product_Variable::sync( $parent_id );
		wc_delete_product_transients( $parent_id );

		return $parent_id;
	}

	/** QA case (product 411959): variations at 110 and 120, no price on the parent → 110. */
	public function test_variable_product_takes_the_cheapest_variation(): void {
		$id  = $this->create_variable( array( array( 'S', '110', '' ), array( 'M', '120', '' ) ) );
		$dto = Product_Mapper::to_dto( $id );

		$this->assertSame( 110.0, (float) $dto['price']['amount'] );
		$this->assertSame( '', $dto['price']['compareAt'] );

		// The variants themselves are unchanged.
		$this->assertTrue( $dto['optionsEnabled'] );
		$this->assertEqualsCanonicalizing( array( 110.0, 120.0 ), array_map( 'floatval', array_column( $dto['variants'], 'price' ) ) );
	}

	/** On sale: amount and compareAt come from the same (cheapest) variation, like the variants. */
	public function test_variable_product_on_sale_keeps_price_and_compare_at_together(): void {
		$id  = $this->create_variable( array( array( 'S', '110', '' ), array( 'M', '120', '90' ) ) );
		$dto = Product_Mapper::to_dto( $id );

		$this->assertSame( 90.0, (float) $dto['price']['amount'] );
		$this->assertSame( 120.0, (float) $dto['price']['compareAt'] );
	}

	/** Simple products keep the existing mapping (regular, or sale when lower). */
	public function test_simple_product_mapping_is_unchanged(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Vio test simple product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '200' );
		$product->set_sale_price( '150' );
		$id              = $product->save();
		$this->created[] = $id;

		$dto = Product_Mapper::to_dto( $id );

		$this->assertSame( '150', (string) $dto['price']['amount'] );
		$this->assertSame( '200', (string) $dto['price']['compareAt'] );
		$this->assertFalse( $dto['optionsEnabled'] );
	}

	/** Cheapest variation wins; variations without a numeric price are ignored. */
	public function test_price_from_variants(): void {
		$this->assertNull( Product_Mapper::price_from_variants( array() ) );
		$this->assertNull( Product_Mapper::price_from_variants( array( array( 'price' => '', 'priceCompareAt' => '' ) ) ) );

		$this->assertSame(
			array( '110', '' ),
			Product_Mapper::price_from_variants(
				array(
					array( 'price' => '120', 'priceCompareAt' => '' ),
					array( 'price' => '', 'priceCompareAt' => '' ),
					array( 'price' => '110', 'priceCompareAt' => '' ),
				)
			)
		);
		$this->assertSame(
			array( '90', '120' ),
			Product_Mapper::price_from_variants(
				array(
					array( 'price' => '110', 'priceCompareAt' => '' ),
					array( 'price' => '90', 'priceCompareAt' => '120' ),
				)
			)
		);
	}

	/** The update diff never sends an empty amount (it would set the product to 0 in Vio). */
	public function test_diff_never_sends_an_empty_amount(): void {
		$vio = array( 'originalPrice' => array( 'amount' => 110, 'compareAt' => 0, 'currencyCode' => 'NOK' ) );

		$changes = Product_Mapper::diff(
			array( 'price' => array( 'amount' => '', 'compareAt' => '', 'currencyCode' => 'NOK' ) ),
			$vio + array( 'price' => array() )
		);
		$this->assertArrayNotHasKey( 'price', $changes );
	}

	/** A product stuck at 0 in Vio gets the real price on its next update. */
	public function test_diff_sends_the_variant_price_over_a_zero(): void {
		$vio = array(
			'price'         => array(),
			'originalPrice' => array( 'amount' => 0, 'compareAt' => 0, 'currencyCode' => 'NOK' ),
		);

		$changes = Product_Mapper::diff(
			array( 'price' => array( 'amount' => '110', 'compareAt' => '', 'currencyCode' => 'NOK' ) ),
			$vio
		);
		$this->assertSame( array( 'currencyCode' => 'NOK', 'amount' => '110' ), $changes['price'] );
	}
}
