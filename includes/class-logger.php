<?php
/**
 * Plugin logger (wrapper around the WooCommerce logger).
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Logger {

	private static ?\WC_Logger_Interface $logger = null;

	private static function logger(): \WC_Logger_Interface {
		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}
		return self::$logger;
	}

	public static function info( string $message ): void {
		self::logger()->info( $message, [ 'source' => Plugin::LOG_SOURCE ] );
	}

	public static function error( string $message ): void {
		self::logger()->error( $message, [ 'source' => Plugin::LOG_SOURCE ] );
	}

	/**
	 * Recent log lines for this plugin's source, newest first. Reads the
	 * WooCommerce file logs (falls back to the DB log handler).
	 *
	 * @return string[]
	 */
	public static function recent( int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );

		$dir   = trailingslashit( (string) wp_upload_dir()['basedir'] ) . 'wc-logs/';
		$files = glob( $dir . Plugin::LOG_SOURCE . '-*.log' );
		if ( $files ) {
			usort(
				$files,
				static function ( $a, $b ) {
					return filemtime( $b ) <=> filemtime( $a );
				}
			);
			$lines = @file( $files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $lines ) && $lines ) {
				return array_reverse( array_slice( $lines, -$limit ) );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT timestamp, level, message FROM {$table} WHERE source = %s ORDER BY log_id DESC LIMIT %d",
					Plugin::LOG_SOURCE,
					$limit
				)
			);
			if ( $rows ) {
				return array_map(
					static function ( $r ) {
						return sprintf( '%s %s %s', $r->timestamp, strtoupper( (string) $r->level ), $r->message );
					},
					$rows
				);
			}
		}

		return array();
	}
}
