<?php
/**
 * Dedicated "Vio" admin page: a top-level menu with a single configuration
 * screen (connection, settings, sync overview, diagnostics).
 *
 * The screen renders its initial state server-side and is progressively
 * enhanced by assets/js/config.js (save, re-check, sync-all, live stats).
 *
 * Output note: every dynamic value below is escaped at the point of output
 * (esc_html / esc_attr / esc_url) or is hard-coded inline SVG produced by
 * self::icon(). The few `phpcs:ignore` annotations mark those inline SVG
 * icons and pre-escaped markup — wrapping the SVG in wp_kses() would lower-case
 * the `viewBox` attribute and break icon scaling.
 *
 * @package Vio\WooSync
 */

declare( strict_types=1 );

namespace Vio\WooSync;

defined( 'ABSPATH' ) || exit;

final class Config_Page {

	public const MENU_SLUG = 'vio';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/**
	 * The admin-menu mark. It sits on the dark sidebar, so it uses a dedicated
	 * white icon (assets/img/icon-white.svg), inlined as a data URI — replacing
	 * that file updates the menu. Falls back to a built-in white mark if it can't
	 * be read. (The colour icon.svg is used on the config page + product column.)
	 */
	private static function menu_icon(): string {
		$file = VIO_WC_SYNC_DIR . 'assets/img/icon-white.svg';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled local asset, not a remote URL.
		$svg  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		if ( '' === $svg ) {
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">'
				. '<path d="M13 15l11 19 11-19" fill="none" stroke="#fff" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>'
				. '</svg>';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Inline outline icon (Feather, MIT). Stroke inherits the current text colour.
	 * Returns hard-coded SVG markup; only the class name is dynamic and escaped.
	 */
	private static function icon( string $name ): string {
		$paths = array(
			'link'      => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
			'sliders'   => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
			'refresh'   => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
			'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
			'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
			'flag'      => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
			'clipboard' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
			'info'      => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
			'chevron'   => '<polyline points="6 9 12 15 18 9"/>',
			'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
			'eye-off'   => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
		);

		return '<svg class="vio-ico vio-ico--' . esc_attr( $name ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. ( $paths[ $name ] ?? '' )
			. '</svg>';
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Vio', 'vio-sync-for-woocommerce' ),
			__( 'Vio', 'vio-sync-for-woocommerce' ),
			Plugin::CAPABILITY,
			self::MENU_SLUG,
			[ self::class, 'render' ],
			self::menu_icon(),
			56
		);

		// Rename the auto-created first submenu item from "Vio" to "Configuration".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Configuration', 'vio-sync-for-woocommerce' ),
			__( 'Configuration', 'vio-sync-for-woocommerce' ),
			Plugin::CAPABILITY,
			self::MENU_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		// Version assets by file mtime so edits bust the browser cache (no manual bump).
		$css_ver = (string) ( @filemtime( VIO_WC_SYNC_DIR . 'assets/css/config.css' ) ?: VIO_WC_SYNC_VERSION ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$js_ver  = (string) ( @filemtime( VIO_WC_SYNC_DIR . 'assets/js/config.js' ) ?: VIO_WC_SYNC_VERSION );  // phpcs:ignore WordPress.PHP.NoSilencedErrors

		wp_enqueue_style( 'vio-wc-sync-config', VIO_WC_SYNC_URL . 'assets/css/config.css', [], $css_ver );
		wp_enqueue_script( 'vio-wc-sync-config', VIO_WC_SYNC_URL . 'assets/js/config.js', [ 'jquery' ], $js_ver, true );
		wp_localize_script(
			'vio-wc-sync-config',
			'vioConfig',
			[
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'vio_sync' ),
				'productsUrl' => admin_url( 'edit.php?post_type=product' ),
				'hasKey'      => Api_Client::has_api_key(),
				'diag'        => Store_Status::diag_string(),
			]
		);
	}

	// --- Render ----------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vio-sync-for-woocommerce' ) );
		}

		// Render fast: no blocking network call here. The connection card starts in
		// a "Checking…" state; config.js runs the health check + reconcile/stats
		// asynchronously on load, so a slow/flaky backend never blocks the page.
		$state = self::initial_state();
		$stats = Store_Status::stats();

		echo '<div class="wrap vio-config">';
		self::render_header( $state );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post-redirect flag, no state change.
		if ( isset( $_GET['vio_disconnected'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post-redirect flag, no state change.
			$deleted = isset( $_GET['vio_deleted'] ) ? absint( wp_unslash( $_GET['vio_deleted'] ) ) : 0;
			$alert   = $deleted
				/* translators: %d: number of products also deleted from Vio */
				? sprintf( __( 'Store disconnected from Vio — removed the API key, REST keys, order webhooks and %d product(s) from Vio.', 'vio-sync-for-woocommerce' ), $deleted )
				: __( 'Store disconnected from Vio — removed the API key, REST keys and order webhooks.', 'vio-sync-for-woocommerce' );
			echo '<div class="vio-notice vio-notice--ok vio-config__alert">' . esc_html( $alert ) . '</div>';
		}

		echo '<div class="vio-config__grid">';
		self::render_connection( $state );
		self::render_settings( $state );
		self::render_sync( $stats, $state );
		self::render_logs();
		echo '</div>';
		self::render_disconnect_modal( (int) $stats['synced'] );
		echo '</div>';
	}

	/**
	 * Local-only connection state for the first paint — no network call. config.js
	 * fills in the live state (account, health, webhooks, key validity) on load.
	 */
	private static function initial_state(): array {
		return array(
			'has_key'   => Api_Client::has_api_key(),
			'pending'   => true,
			'connected' => false,
			'valid'     => false,
			'reachable' => false,
			'status'    => 0,
			'user'      => null,
			'latency'   => 0,
		);
	}

	private static function render_header( array $state ): void {
		$env = Api_Client::environment();

		if ( ! empty( $state['pending'] ) && $state['has_key'] ) {
			$badge = 'idle';
			$label = __( 'Checking…', 'vio-sync-for-woocommerce' );
		} elseif ( $state['connected'] ) {
			$badge = 'ok';
			$label = sprintf( /* translators: %s: environment */ __( 'Connected · %s', 'vio-sync-for-woocommerce' ), $env );
		} elseif ( $state['has_key'] ) {
			$badge = 'warn';
			$label = __( 'Not connected', 'vio-sync-for-woocommerce' );
		} else {
			$badge = 'idle';
			$label = __( 'Not configured', 'vio-sync-for-woocommerce' );
		}

		// Brand: the plugin's logo (assets/img/icon.svg). The logo carries the
		// name, so there's no separate "Vio" wordmark.
		$brand = sprintf(
			'<img class="vio-config__logo" src="%s" alt="Vio" />',
			esc_url( VIO_WC_SYNC_URL . 'assets/img/icon.svg' )
		);

		printf(
			'<div class="vio-config__head">
				<div class="vio-config__brand">
					%1$s
					<span class="vio-config__sep"></span>
					<span class="vio-config__subtitle">%2$s</span>
				</div>
				<span class="vio-badge vio-badge--%3$s"><span class="vio-dot"></span>%4$s</span>
			</div>',
			$brand, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url() + literal markup.
			esc_html__( 'Configuration', 'vio-sync-for-woocommerce' ),
			esc_attr( $badge ),
			esc_html( $label )
		);
	}

	private static function render_connection( array $state ): void {
		$env        = Api_Client::environment();
		$host       = (string) wp_parse_url( Api_Client::base_url(), PHP_URL_HOST );
		$user       = $state['user'];
		$account    = $user && isset( $user->username ) ? (string) $user->username : ( $user && isset( $user->email ) ? (string) $user->email : '—' );
		$disconnect = wp_nonce_url( admin_url( 'admin-ajax.php?action=vio_logout' ), 'vio_logout' );

		echo '<section class="vio-card vio-card--connection" id="vio-connection">';
		echo '<h2 class="vio-card__title">' . self::icon( 'link' ) . esc_html__( 'Connection', 'vio-sync-for-woocommerce' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.

		if ( ! $state['has_key'] ) {
			echo '<p class="vio-muted">' . esc_html__( 'Enter your API key below and save to connect this store with Vio.', 'vio-sync-for-woocommerce' ) . '</p>';
			echo '</section>';
			return;
		}

		// Prominent, status-aware notice (401 rejected vs network error). The slot
		// is always present so the JS "Re-check" can refresh it without a reload.
		// While the first-load health check is pending, suppress it — don't flash a
		// false "network error" before the async check answers.
		$pending = ! empty( $state['pending'] );
		$message = $pending ? '' : Store_Status::connection_message( $state );
		echo '<div class="vio-conn-notice" id="vio-conn-notice">';
		if ( '' !== $message ) {
			echo '<div class="vio-notice vio-notice--error">' . esc_html( $message ) . '</div>';
		}
		echo '</div>';

		$ok       = '<span class="vio-ok"><span class="vio-dot"></span>%s</span>';
		$bad      = '<span class="vio-bad">%s</span>';
		$checking = '<span class="vio-skeleton" aria-label="' . esc_attr__( 'Checking…', 'vio-sync-for-woocommerce' ) . '"></span>';

		echo '<dl class="vio-kv">';
		self::kv( __( 'Account', 'vio-sync-for-woocommerce' ), $pending ? $checking : esc_html( $account ), 'account' );
		self::kv( __( 'Environment', 'vio-sync-for-woocommerce' ), esc_html( ucfirst( $env ) ), 'environment' );
		self::kv( __( 'API endpoint', 'vio-sync-for-woocommerce' ), '<code>' . esc_html( $host ) . '</code>', 'host' );
		self::kv(
			__( 'API health', 'vio-sync-for-woocommerce' ),
			$pending ? $checking : (
				$state['reachable']
					? sprintf( $ok, sprintf( /* translators: %d: milliseconds */ esc_html__( 'Reachable · %d ms', 'vio-sync-for-woocommerce' ), (int) $state['latency'] ) )
					: sprintf( $bad, esc_html__( 'Unreachable', 'vio-sync-for-woocommerce' ) )
			),
			'health'
		);
		self::kv(
			__( 'Order webhooks', 'vio-sync-for-woocommerce' ),
			$pending ? $checking : ( $state['connected'] ? sprintf( $ok, esc_html__( 'Active', 'vio-sync-for-woocommerce' ) ) : sprintf( $bad, esc_html__( 'Not set up', 'vio-sync-for-woocommerce' ) ) ),
			'webhooks'
		);
		self::kv(
			__( 'API key', 'vio-sync-for-woocommerce' ),
			$pending ? $checking : (
				$state['valid']
					? sprintf( $ok, esc_html__( 'Valid', 'vio-sync-for-woocommerce' ) )
					: ( in_array( (int) $state['status'], array( 401, 403 ), true )
						/* translators: %d: HTTP status code */
						? sprintf( $bad, sprintf( esc_html__( 'Rejected (HTTP %d)', 'vio-sync-for-woocommerce' ), (int) $state['status'] ) )
						: sprintf( $bad, esc_html__( 'Not verified', 'vio-sync-for-woocommerce' ) ) )
			),
			'restkey'
		);
		echo '</dl>';

		echo '<div class="vio-card__actions">';
		echo '<button type="button" class="button vio-btn" id="vio-recheck"><span class="vio-spinner"></span>' . self::icon( 'refresh' ) . esc_html__( 'Re-check', 'vio-sync-for-woocommerce' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.

		if ( $state['has_key'] ) {
			echo '<a class="button vio-btn vio-btn--danger" id="vio-disconnect" href="' . esc_url( $disconnect ) . '">' . esc_html__( 'Disconnect', 'vio-sync-for-woocommerce' ) . '</a>';
		}
		echo '</div>';

		echo '</section>';
	}

	private static function render_settings( array $state ): void {
		$env_locked = defined( 'VIO_WC_SYNC_ENV' ) && isset( Api_Client::ENVIRONMENTS[ VIO_WC_SYNC_ENV ] );
		$env        = Api_Client::environment();
		$currency   = (string) get_option( Plugin::OPT_CURRENCY );
		$api_key    = Api_Client::api_key();
		$currencies = Store_Status::currency_options();
		// Once a key is saved the store is connected: lock the settings so they
		// can't be changed by accident. To edit, disconnect first (Connection card).
		$locked     = ! empty( $state['has_key'] );

		echo '<section class="vio-card vio-card--settings">';
		echo '<h2 class="vio-card__title">' . self::icon( 'sliders' ) . esc_html__( 'Settings', 'vio-sync-for-woocommerce' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '<form id="vio-settings-form" class="vio-form">';

		// API key.
		echo '<label class="vio-field"><span class="vio-field__label">' . esc_html__( 'API key', 'vio-sync-for-woocommerce' ) . '</span>';
		echo '<span class="vio-field__control vio-field__control--key">';
		printf(
			'<input type="password" id="vio-apikey" name="apikey" value="%s" autocomplete="off" spellcheck="false"%s />',
			esc_attr( $api_key ),
			$locked ? ' disabled' : ''
		);
		echo '<button type="button" class="vio-reveal" id="vio-reveal" aria-label="' . esc_attr__( 'Show or hide the API key', 'vio-sync-for-woocommerce' ) . '">' . self::icon( 'eye' ) . self::icon( 'eye-off' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icons.
		echo '</span></label>';

		// Environment.
		echo '<label class="vio-field"><span class="vio-field__label">' . esc_html__( 'Environment', 'vio-sync-for-woocommerce' ) . '</span>';
		echo '<span class="vio-field__control">';
		printf( '<select id="vio-environment" name="environment"%s>', ( $env_locked || $locked ) ? ' disabled' : '' );
		foreach ( [ 'production' => __( 'Production', 'vio-sync-for-woocommerce' ), 'staging' => __( 'Staging', 'vio-sync-for-woocommerce' ) ] as $value => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $env, $value, false ), esc_html( $text ) );
		}
		echo '</select>';
		if ( $env_locked ) {
			echo '<span class="vio-hint">' . esc_html__( 'Locked by the VIO_WC_SYNC_ENV constant in wp-config.php.', 'vio-sync-for-woocommerce' ) . '</span>';
		}
		echo '</span></label>';

		// Currency.
		echo '<label class="vio-field"><span class="vio-field__label">' . esc_html__( 'Currency', 'vio-sync-for-woocommerce' ) . '</span>';
		echo '<span class="vio-field__control">';
		echo '<select id="vio-currency" name="currency"' . ( $locked ? ' disabled' : '' ) . '>';
		foreach ( $currencies as $code => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $currency, $code, false ), esc_html( $text ) );
		}
		echo '</select></span></label>';

		// One-step Connect when there's no key yet. Once a key is saved the form is
		// locked ($locked above) — to change a setting, disconnect and reconnect.
		if ( $locked ) {
			echo '<p class="vio-hint">' . esc_html__( 'Connected — disconnect to change the key, environment or currency.', 'vio-sync-for-woocommerce' ) . '</p>';
		}
		echo '<div class="vio-card__actions">';
		printf(
			'<button type="submit" class="button button-primary vio-btn vio-btn--primary" id="vio-save" data-action="connect"%s><span class="vio-spinner"></span>%s<span class="vio-save-label">%s</span></button>',
			$locked ? ' disabled' : '',
			$locked ? '' : self::icon( 'link' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
			esc_html__( 'Connect', 'vio-sync-for-woocommerce' )
		);
		echo '<span class="vio-save-feedback" id="vio-save-feedback" aria-live="polite"></span>';
		echo '</div>';

		echo '</form></section>';
	}

	private static function render_sync( array $stats, array $state ): void {
		$idle = $state['connected'] ? '' : ' disabled';

		echo '<section class="vio-card vio-card--sync">';
		echo '<div class="vio-card__headrow">';
		echo '<h2 class="vio-card__title">' . self::icon( 'refresh' ) . esc_html__( 'Sync overview', 'vio-sync-for-woocommerce' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '<div class="vio-card__actions">';
		echo '<button type="button" class="button button-primary vio-btn vio-btn--primary" id="vio-sync-all"' . esc_attr( $idle ) . '><span class="vio-spinner"></span>' . self::icon( 'upload' ) . esc_html__( 'Sync all', 'vio-sync-for-woocommerce' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '<button type="button" class="button vio-btn" id="vio-finish-sync"' . esc_attr( $idle ) . '><span class="vio-spinner"></span>' . self::icon( 'flag' ) . esc_html__( 'Finish first sync', 'vio-sync-for-woocommerce' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '</div></div>';

		echo '<div class="vio-metrics" id="vio-metrics">';
		self::metric( 'total', __( 'Total products', 'vio-sync-for-woocommerce' ), $stats['total'] );
		self::metric( 'synced', __( 'Synced', 'vio-sync-for-woocommerce' ), $stats['synced'] );
		self::metric( 'sent', __( 'Sent · queued', 'vio-sync-for-woocommerce' ), $stats['sent'] );
		self::metric( 'not-synced', __( 'Not synced', 'vio-sync-for-woocommerce' ), $stats['not_synced'] );
		echo '</div>';

		echo '<div class="vio-syncing" id="vio-sync-progress" hidden>'
			. '<div class="vio-syncing__head"><span class="vio-syncing__spinner"></span><span class="vio-syncing__label"></span><span class="vio-syncing__count"></span></div>'
			. '<div class="vio-syncing__bar"><span></span></div>'
			. '<ul class="vio-syncing__items"></ul>'
			. '</div>';

		if ( $stats['sent'] > 0 ) {
			echo '<p class="vio-note">';
			echo self::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
			printf(
				/* translators: %d: number of queued products */
				esc_html__( '%d queued — waiting for Vio to return the product-id (backend).', 'vio-sync-for-woocommerce' ),
				(int) $stats['sent']
			);
			echo '</p>';
		}

		echo '</section>';
	}

	private static function render_logs(): void {
		echo '<section class="vio-card vio-card--logs" id="vio-logs">';
		echo '<div class="vio-card__headrow vio-logs__toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="vio-logs-body">';
		echo '<h2 class="vio-card__title">' . self::icon( 'file-text' ) . esc_html__( 'Logs', 'vio-sync-for-woocommerce' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '<span class="vio-logs__chev">' . self::icon( 'chevron' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '</div>';

		echo '<div class="vio-logs__body" id="vio-logs-body">';
		echo '<div class="vio-logs__toolbar">';
		echo '<button type="button" class="button vio-btn" id="vio-logs-refresh"><span class="vio-spinner"></span>' . self::icon( 'refresh' ) . esc_html__( 'Refresh', 'vio-sync-for-woocommerce' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '<button type="button" class="button vio-btn" id="vio-logs-copy">' . self::icon( 'clipboard' ) . esc_html__( 'Copy for support', 'vio-sync-for-woocommerce' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '<span class="vio-logs__source">' . esc_html( Plugin::LOG_SOURCE ) . '</span>';
		echo '</div>';
		echo '<pre class="vio-logs__pre" id="vio-logs-pre"><span class="vio-muted">' . esc_html__( 'Expand to load recent activity…', 'vio-sync-for-woocommerce' ) . '</span></pre>';
		echo '</div></section>';
	}

	/**
	 * Confirmation modal for Disconnect. Offers (checked by default) to also
	 * delete the store's synced products in Vio, with a disclaimer. The choice
	 * is read back by Ajax::logout() via the `vio_delete` flag.
	 */
	private static function render_disconnect_modal( int $synced ): void {
		echo '<div class="vio-modal" id="vio-disconnect-modal" hidden>';
		echo '<div class="vio-modal__overlay" data-close></div>';
		echo '<div class="vio-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="vio-disconnect-title">';
		echo '<h2 class="vio-modal__title" id="vio-disconnect-title">' . self::icon( 'link' ) . esc_html__( 'Disconnect from Vio', 'vio-sync-for-woocommerce' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
		echo '<p class="vio-modal__text">' . esc_html__( 'This removes the API key, REST keys and order webhooks for this store.', 'vio-sync-for-woocommerce' ) . '</p>';

		if ( $synced > 0 ) {
			$count_html = '<strong data-key="synced-count">' . absint( $synced ) . '</strong>';
			echo '<label class="vio-modal__check"><input type="checkbox" id="vio-delete-products" checked /> ';
			echo sprintf(
				/* translators: %s: number of synced products. */
				esc_html__( 'Also delete my %s synced product(s) from Vio', 'vio-sync-for-woocommerce' ),
				$count_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hard-coded markup wrapping an absint() count.
			);
			echo '</label>';

			echo '<p class="vio-modal__disclaimer" id="vio-delete-disclaimer">';
			echo self::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG icon.
			echo esc_html__( 'Your products will be deleted from Vio together with the connection. Vio soft-deletes them — existing references won’t break, but the products stop being available.', 'vio-sync-for-woocommerce' );
			echo '</p>';
		}

		echo '<div class="vio-modal__actions">';
		echo '<button type="button" class="button vio-btn" data-close>' . esc_html__( 'Cancel', 'vio-sync-for-woocommerce' ) . '</button>';
		echo '<button type="button" class="button vio-btn vio-btn--danger" id="vio-disconnect-confirm">' . esc_html__( 'Disconnect', 'vio-sync-for-woocommerce' ) . '</button>';
		echo '</div>';

		echo '</div></div>';
	}

	// --- Small render helpers -------------------------------------------

	private static function kv( string $label, string $value_html, string $field = '' ): void {
		printf(
			'<div class="vio-kv__row"><dt>%s</dt><dd%s>%s</dd></div>',
			esc_html( $label ),
			'' !== $field ? ' data-field="' . esc_attr( $field ) . '"' : '',
			$value_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped markup built by each caller (esc_html / sprintf with esc_html).
		);
	}

	private static function metric( string $key, string $label, int $value ): void {
		printf(
			'<div class="vio-metric vio-metric--%s"><span class="vio-metric__label">%s</span><span class="vio-metric__value" data-key="%s">%d</span></div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $key ),
			(int) $value
		);
	}
}
