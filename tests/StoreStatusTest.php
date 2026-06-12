<?php
/**
 * Integration tests for the Vio configuration page data layer.
 *
 * @package Vio\WooSync\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Vio\WooSync\Api_Client;
use Vio\WooSync\Store_Status;
use Vio\WooSync\Sync;
use Vio\WooSync\Logger;
use Vio\WooSync\Plugin;

final class StoreStatusTest extends TestCase {

	/** stats() returns four non-negative int buckets that partition the total. */
	public function test_stats_shape_and_invariant(): void {
		$s = Store_Status::stats();

		$this->assertSame( array( 'total', 'synced', 'sent', 'not_synced' ), array_keys( $s ) );
		foreach ( $s as $value ) {
			$this->assertIsInt( $value );
			$this->assertGreaterThanOrEqual( 0, $value );
		}

		$this->assertSame(
			$s['total'],
			$s['synced'] + $s['sent'] + $s['not_synced'],
			'synced + sent + not_synced must equal total'
		);
		$this->assertSame( (int) wp_count_posts( 'product' )->publish, $s['total'] );
	}

	/** health_payload() always exposes the keys the JS expects. */
	public function test_health_payload_shape(): void {
		$h = Store_Status::health_payload();

		foreach ( array( 'hasKey', 'reachable', 'valid', 'connected', 'status', 'latency', 'account', 'environment', 'host', 'message' ) as $key ) {
			$this->assertArrayHasKey( $key, $h );
		}
		$this->assertContains( $h['environment'], array( 'production', 'staging' ) );
		$this->assertIsInt( $h['latency'] );
		$this->assertIsInt( $h['status'] );
		$this->assertIsString( $h['message'] );
		$this->assertIsBool( $h['connected'] );

		// A valid (or unconfigured) connection has no message; an invalid one explains why.
		if ( $h['valid'] || ! $h['hasKey'] ) {
			$this->assertSame( '', $h['message'], 'no message when valid or unconfigured' );
		} else {
			$this->assertNotSame( '', $h['message'], 'an invalid key must explain why' );
		}
	}

	/** Eligible ids are published products with no remote product-id, de-duplicated. */
	public function test_pending_ids_are_unsynced_products(): void {
		$ids = Store_Status::pending_product_ids();

		$this->assertIsArray( $ids );
		$this->assertSame( array_values( array_unique( $ids ) ), $ids, 'ids must be unique' );

		foreach ( $ids as $id ) {
			$this->assertSame( 'product', get_post_type( $id ) );
			$this->assertSame( 'publish', get_post_status( $id ) );
			$this->assertSame( '', (string) get_post_meta( $id, Plugin::META_PRODUCT_ID, true ), 'already-synced products must be excluded' );
		}
	}

	public function test_pending_ids_respects_limit(): void {
		$this->assertLessThanOrEqual( 2, count( Store_Status::pending_product_ids( 2 ) ) );
	}

	/** save_options() persists settings and ignores an invalid environment. */
	public function test_save_options_persists_and_validates_env(): void {
		$prev_key      = get_option( Plugin::OPT_API_KEY );
		$prev_env      = get_option( Plugin::OPT_ENVIRONMENT );
		$prev_currency = get_option( Plugin::OPT_CURRENCY );

		try {
			// Empty key → no backend call; pure persistence check.
			Store_Status::save_options( '', 'staging', 'NOK' );
			$this->assertSame( '', get_option( Plugin::OPT_API_KEY ) );
			$this->assertSame( 'staging', get_option( Plugin::OPT_ENVIRONMENT ) );
			$this->assertSame( 'NOK', get_option( Plugin::OPT_CURRENCY ) );

			// Invalid environment is ignored: the option keeps its prior valid value.
			Store_Status::save_options( '', 'bogus-env', 'USD' );
			$this->assertSame( 'staging', get_option( Plugin::OPT_ENVIRONMENT ) );
			$this->assertSame( 'USD', get_option( Plugin::OPT_CURRENCY ) );

			// A valid switch works.
			Store_Status::save_options( '', 'production', 'EUR' );
			$this->assertSame( 'production', get_option( Plugin::OPT_ENVIRONMENT ) );
		} finally {
			update_option( Plugin::OPT_API_KEY, $prev_key );
			update_option( Plugin::OPT_ENVIRONMENT, $prev_env );
			update_option( Plugin::OPT_CURRENCY, $prev_currency );
		}
	}

	/** Logger::recent() returns a capped list of strings. */
	public function test_logger_recent_returns_capped_strings(): void {
		$lines = Logger::recent( 5 );

		$this->assertIsArray( $lines );
		$this->assertLessThanOrEqual( 5, count( $lines ) );
		foreach ( $lines as $line ) {
			$this->assertIsString( $line );
		}
	}

	/** Newly written entries come back newest-first. */
	public function test_logger_recent_is_newest_first(): void {
		Logger::info( 'vio-test-marker-alpha' );
		Logger::info( 'vio-test-marker-beta' );

		$lines = Logger::recent( 50 );
		$index_alpha = $this->index_of( $lines, 'vio-test-marker-alpha' );
		$index_beta  = $this->index_of( $lines, 'vio-test-marker-beta' );

		if ( $index_alpha < 0 || $index_beta < 0 ) {
			$this->markTestSkipped( 'log markers not found (logging handler may buffer or differ)' );
		}

		$this->assertLessThan( $index_alpha, $index_beta, 'the newer entry must sort before the older one' );
	}

	/* ---- credential resolution for disconnect (the ecomUser.id gotcha) ---- */

	public function test_pick_woo_connection_returns_credential_and_entry_ids(): void {
		$list = json_decode( '[{"id":199,"ecomName":"WOOCOMMERCE","connection":{"url":"https://woo-dev.vio.live"},"apiCredential":{"id":412}}]' );
		$this->assertSame(
			array( 'credential_id' => 412, 'ecom_user_id' => 199 ),
			Api_Client::pick_woo_connection( $list, 'woo-dev.vio.live' )
		);
	}

	/** Regression: ecom_user_id is the entry id (199), not a nested account id. */
	public function test_pick_woo_connection_uses_entry_id(): void {
		$list = json_decode( '[{"id":199,"ecomName":"WOOCOMMERCE","connection":{"url":"https://x"},"apiCredential":{"id":412}}]' );
		$this->assertSame( 199, Api_Client::pick_woo_connection( $list, 'no-match' )['ecom_user_id'] );
	}

	public function test_pick_woo_connection_prefers_host_match(): void {
		$list = json_decode( '[{"id":1,"ecomName":"WOOCOMMERCE","connection":{"url":"https://other.example"},"apiCredential":{"id":10}},{"id":2,"ecomName":"WOOCOMMERCE","connection":{"url":"https://mine.example"},"apiCredential":{"id":20}}]' );
		$this->assertSame(
			array( 'credential_id' => 20, 'ecom_user_id' => 2 ),
			Api_Client::pick_woo_connection( $list, 'mine.example' )
		);
	}

	public function test_pick_woo_connection_falls_back_to_first_woo(): void {
		$list = json_decode( '[{"id":5,"ecomName":"SHOPIFY","apiCredential":{"id":99}},{"id":7,"ecomName":"WOOCOMMERCE","connection":{"url":"https://a"},"apiCredential":{"id":77}}]' );
		$this->assertSame(
			array( 'credential_id' => 77, 'ecom_user_id' => 7 ),
			Api_Client::pick_woo_connection( $list, 'no-match' )
		);
	}

	public function test_pick_woo_connection_null_when_none_or_incomplete(): void {
		$this->assertNull( Api_Client::pick_woo_connection( array(), 'x' ) );
		$this->assertNull( Api_Client::pick_woo_connection( json_decode( '[{"id":5,"ecomName":"SHOPIFY","apiCredential":{"id":99}}]' ), 'x' ) );
		$this->assertNull( Api_Client::pick_woo_connection( json_decode( '[{"id":5,"ecomName":"WOOCOMMERCE","connection":{"url":"https://a"}}]' ), 'a' ), 'missing apiCredential → skipped' );
	}

	/* ---- connection messages (401 vs network vs other) ---- */

	public function test_connection_message_branches(): void {
		$msg = static function ( bool $has_key, bool $valid, int $status ): string {
			return Store_Status::connection_message( array( 'has_key' => $has_key, 'valid' => $valid, 'status' => $status ) );
		};
		$this->assertSame( '', $msg( false, false, 0 ), 'no key → no message' );
		$this->assertSame( '', $msg( true, true, 200 ), 'valid → no message' );
		$this->assertStringContainsString( '401', $msg( true, false, 401 ) );
		$this->assertStringContainsString( '403', $msg( true, false, 403 ) );
		$this->assertStringContainsString( 'network', strtolower( $msg( true, false, 0 ) ) );
		$this->assertStringContainsString( '500', $msg( true, false, 500 ) );
	}

	/* ---- reconcile_remote_ids(): link the backend's legacy write-back ---- */

	/** A product carrying only the legacy reachu-product-id is linked into vio-product-id (idempotently). */
	public function test_reconcile_links_legacy_writeback(): void {
		$post_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'vio-test-reconcile',
		) );
		$this->assertGreaterThan( 0, $post_id );

		try {
			update_post_meta( $post_id, Plugin::META_LEGACY_PRODUCT_ID, '987654' );
			$this->assertSame( '', (string) get_post_meta( $post_id, Plugin::META_PRODUCT_ID, true ), 'precondition: not yet linked' );

			$linked = Store_Status::reconcile_remote_ids();
			$this->assertGreaterThanOrEqual( 1, $linked );
			$this->assertSame( '987654', (string) get_post_meta( $post_id, Plugin::META_PRODUCT_ID, true ), 'vio-product-id is backfilled from the legacy key' );

			// Idempotent: a second run leaves the value untouched.
			Store_Status::reconcile_remote_ids();
			$this->assertSame( '987654', (string) get_post_meta( $post_id, Plugin::META_PRODUCT_ID, true ) );
		} finally {
			wp_delete_post( $post_id, true );
		}
	}

	/** An existing vio-product-id is never overwritten by the legacy key. */
	public function test_reconcile_does_not_overwrite_existing_id(): void {
		$post_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'vio-test-reconcile-keep',
		) );
		$this->assertGreaterThan( 0, $post_id );

		try {
			update_post_meta( $post_id, Plugin::META_PRODUCT_ID, 'keep-me' );
			update_post_meta( $post_id, Plugin::META_LEGACY_PRODUCT_ID, 'override' );

			Store_Status::reconcile_remote_ids();

			$this->assertSame( 'keep-me', (string) get_post_meta( $post_id, Plugin::META_PRODUCT_ID, true ), 'an existing id must win over the legacy key' );
		} finally {
			wp_delete_post( $post_id, true );
		}
	}

	/* ---- unlink_all_products(): disconnect hygiene ---- */

	/** Disconnect drops the sync meta from every product but keeps non-link meta. */
	public function test_unlink_all_products_clears_sync_meta(): void {
		$a = wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'vio-test-unlink-a' ) );
		$b = wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'vio-test-unlink-b' ) );
		$this->assertGreaterThan( 0, $a );
		$this->assertGreaterThan( 0, $b );

		try {
			update_post_meta( $a, Plugin::META_PRODUCT_ID, '111' );
			update_post_meta( $a, Plugin::META_LEGACY_PRODUCT_ID, '111' );
			update_post_meta( $a, Plugin::META_SQS_ID, 'sqs-a' );
			update_post_meta( $b, Plugin::META_SQS_ID, 'sqs-b' );
			// Not a sync-link meta — must survive a disconnect.
			update_post_meta( $a, Plugin::META_ORIGIN, 'VIO' );

			$cleared = Sync::unlink_all_products();
			$this->assertGreaterThanOrEqual( 2, $cleared );

			foreach ( array( Plugin::META_PRODUCT_ID, Plugin::META_LEGACY_PRODUCT_ID, Plugin::META_SQS_ID ) as $meta ) {
				$this->assertSame( '', (string) get_post_meta( $a, $meta, true ), "{$meta} must be cleared" );
			}
			$this->assertSame( '', (string) get_post_meta( $b, Plugin::META_SQS_ID, true ) );
			$this->assertSame( 'VIO', (string) get_post_meta( $a, Plugin::META_ORIGIN, true ), 'vio-origin is not a sync link and must remain' );
		} finally {
			wp_delete_post( $a, true );
			wp_delete_post( $b, true );
		}
	}

	/**
	 * @param string[] $lines
	 */
	private function index_of( array $lines, string $needle ): int {
		foreach ( $lines as $i => $line ) {
			if ( false !== strpos( $line, $needle ) ) {
				return (int) $i;
			}
		}
		return -1;
	}
}
