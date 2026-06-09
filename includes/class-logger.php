<?php
/**
 * Logger del plugin (wrapper del logger de WooCommerce).
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
}
