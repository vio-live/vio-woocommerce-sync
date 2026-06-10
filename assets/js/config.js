/**
 * Vio configuration page — interactions with loaders + effects.
 * Progressive enhancement over the server-rendered screen.
 */
( function ( $ ) {
	'use strict';

	var cfg  = window.vioConfig || {};
	var $doc = $( document );

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, $.extend( { action: action, nonce: cfg.nonce }, data || {} ) );
	}

	function spin( $btn, on ) {
		$btn.prop( 'disabled', on ).toggleClass( 'is-loading', on );
	}

	function feedback( msg, ok ) {
		var $fb = $( '#vio-save-feedback' );
		$fb.text( msg ).removeClass( 'is-ok is-err' ).addClass( 'is-visible ' + ( ok ? 'is-ok' : 'is-err' ) );
		setTimeout( function () { $fb.removeClass( 'is-visible' ); }, 2600 );
	}

	function chunk( arr, n ) {
		var out = [];
		for ( var i = 0; i < arr.length; i += n ) { out.push( arr.slice( i, i + n ) ); }
		return out;
	}

	function cap( s ) { s = String( s || '' ); return s.charAt( 0 ).toUpperCase() + s.slice( 1 ); }
	function esc( s ) { return $( '<div>' ).text( s == null ? '' : s ).html(); }

	/* 1) Reveal / hide the API key. */
	$doc.on( 'click', '#vio-reveal', function () {
		var $input = $( '#vio-apikey' );
		var reveal = $input.attr( 'type' ) === 'password';
		$input.attr( 'type', reveal ? 'text' : 'password' );
		$( this ).toggleClass( 'is-revealed', reveal );
	} );

	/* 2) Save settings. */
	$doc.on( 'submit', '#vio-settings-form', function ( e ) {
		e.preventDefault();
		var $btn = $( '#vio-save' );
		var mode = $btn.data( 'action' );
		var data = {
			apikey: $( '#vio-apikey' ).val(),
			environment: $( '#vio-environment' ).val(),
			currency: $( '#vio-currency' ).val()
		};
		spin( $btn, true );

		// "Connect" mode: save + validate, then jump straight to OAuth.
		if ( 'connect' === mode ) {
			post( 'vio_connect', data ).done( function ( r ) {
				if ( r && r.success && r.data && r.data.authUrl ) {
					feedback( 'Connecting…', true );
					window.location.href = r.data.authUrl;
				} else {
					spin( $btn, false );
					var msg = ( r && r.data && r.data.message ) || 'Could not connect';
					feedback( msg, false );
					$( '#vio-conn-notice' ).html( '<div class="vio-notice vio-notice--error">' + esc( msg ) + '</div>' );
				}
			} ).fail( function () {
				spin( $btn, false );
				feedback( 'Could not connect', false );
			} );
			return;
		}

		// "Save changes" mode (already connected): persist and refresh.
		post( 'vio_save_config', data ).done( function ( r ) {
			if ( r && r.success ) {
				feedback( 'Saved', true );
				setTimeout( function () { window.location.reload(); }, 650 );
			} else {
				spin( $btn, false );
				feedback( 'Couldn’t save', false );
			}
		} ).fail( function () {
			spin( $btn, false );
			feedback( 'Couldn’t save', false );
		} );
	} );

	/* 3) Re-check connection — update the card in place. */
	$doc.on( 'click', '#vio-recheck', function () {
		var $btn = $( this );
		spin( $btn, true );
		post( 'vio_health' ).done( function ( r ) {
			if ( r && r.success ) { applyHealth( r.data ); }
		} ).always( function () { spin( $btn, false ); } );
	} );

	function setField( name, html ) { $( '[data-field="' + name + '"]' ).html( html ); }

	function applyHealth( d ) {
		setField( 'account', esc( d.account || '—' ) );
		setField( 'environment', esc( cap( d.environment ) ) );
		setField( 'host', '<code>' + esc( d.host ) + '</code>' );
		setField( 'health', d.reachable
			? '<span class="vio-ok"><span class="vio-dot"></span>Reachable · ' + parseInt( d.latency, 10 ) + ' ms</span>'
			: '<span class="vio-bad">Unreachable</span>' );
		setField( 'webhooks', d.connected
			? '<span class="vio-ok"><span class="vio-dot"></span>Active</span>'
			: '<span class="vio-bad">Not set up</span>' );
		setField( 'restkey', d.valid
			? '<span class="vio-ok"><span class="vio-dot"></span>Valid</span>'
			: ( ( 401 === d.status || 403 === d.status )
				? '<span class="vio-bad">Rejected (HTTP ' + d.status + ')</span>'
				: '<span class="vio-bad">Not verified</span>' ) );

		$( '#vio-conn-notice' ).html( d.message ? '<div class="vio-notice vio-notice--error">' + esc( d.message ) + '</div>' : '' );

		var $b = $( '.vio-badge' ), cls = 'vio-badge--idle', label = 'Not configured';
		if ( d.connected ) { cls = 'vio-badge--ok'; label = 'Connected · ' + d.environment; }
		else if ( d.hasKey ) { cls = 'vio-badge--warn'; label = 'Not connected'; }
		$b.removeClass( 'vio-badge--ok vio-badge--warn vio-badge--idle' ).addClass( cls )
			.html( '<span class="vio-dot"></span>' + esc( label ) );
	}

	/* 4) Sync all — fetch eligible ids, push in chunks, show progress. */
	$doc.on( 'click', '#vio-sync-all', function () {
		var $btn = $( this );
		var $progress = $( '#vio-sync-progress' );
		var $bar = $progress.find( '.vio-progress-inline__bar span' );
		var $text = $progress.find( '.vio-progress-inline__text' );
		spin( $btn, true );

		post( 'vio_pending_ids' ).done( function ( r ) {
			var ids = ( r && r.success && r.data && r.data.ids ) ? r.data.ids : [];
			if ( ! ids.length ) {
				spin( $btn, false );
				feedback( 'Nothing to sync', true );
				return;
			}

			$progress.prop( 'hidden', false );
			var chunks = chunk( ids, 5 ), total = ids.length, done = 0, i = 0, anyOk = false;

			function next() {
				if ( i >= chunks.length ) {
					if ( anyOk ) { post( 'vio_finish_sync' ); }
					$text.text( 'Done' );
					refreshStats();
					setTimeout( function () { $progress.prop( 'hidden', true ); $bar.css( 'width', '0%' ); }, 1000 );
					spin( $btn, false );
					return;
				}
				var c = chunks[ i++ ];
				post( 'vio_sync', { id_posts: c } )
					.done( function () { anyOk = true; } )
					.always( function () {
						done += c.length;
						$bar.css( 'width', Math.round( ( done * 100 ) / total ) + '%' );
						$text.text( done + ' / ' + total );
						next();
					} );
			}
			next();
		} ).fail( function () { spin( $btn, false ); } );
	} );

	/* 5) Finish first sync. */
	$doc.on( 'click', '#vio-finish-sync', function () {
		var $btn = $( this );
		spin( $btn, true );
		post( 'vio_finish_sync' ).always( function () {
			spin( $btn, false );
			feedback( 'First sync marked complete', true );
		} );
	} );

	/* 6) Logs — collapsible, lazy-loaded on first expand. */
	var logsLoaded = false;

	function renderLogs( lines ) {
		var $pre = $( '#vio-logs-pre' );
		if ( ! lines || ! lines.length ) {
			$pre.html( '<span class="vio-muted">No recent activity.</span>' );
			return;
		}
		$pre.html( lines.map( function ( l ) {
			var err = /(^|\s)(ERROR|error|Fatal)(\s|:)/.test( l );
			return '<span class="vio-log-line' + ( err ? ' vio-log-line--error' : '' ) + '">' + esc( l ) + '</span>';
		} ).join( '\n' ) );
	}

	function loadLogs() {
		var $btn = $( '#vio-logs-refresh' );
		spin( $btn, true );
		post( 'vio_logs' ).done( function ( r ) {
			if ( r && r.success ) { renderLogs( r.data.lines ); logsLoaded = true; }
		} ).always( function () { spin( $btn, false ); } );
	}

	$doc.on( 'click', '#vio-logs .vio-logs__toggle', function () {
		var open = $( '#vio-logs' ).toggleClass( 'is-open' ).hasClass( 'is-open' );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
		if ( open && ! logsLoaded ) { loadLogs(); }
	} );

	$doc.on( 'keydown', '#vio-logs .vio-logs__toggle', function ( e ) {
		if ( 'Enter' === e.key || ' ' === e.key ) { e.preventDefault(); $( this ).trigger( 'click' ); }
	} );

	$doc.on( 'click', '#vio-logs-refresh', function () { loadLogs(); } );

	$doc.on( 'click', '#vio-logs-copy', function () {
		var text = ( cfg.diag ? 'Vio diagnostics\n' + cfg.diag + '\n\n' : '' ) + 'Recent logs\n' + $( '#vio-logs-pre' ).text();
		if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( text ); }
		feedback( 'Copied', true );
	} );

	/* 7) Disconnect — confirm before following the link. */
	$doc.on( 'click', '#vio-disconnect', function ( e ) {
		if ( ! window.confirm( 'Disconnect this store from Vio? This removes the Vio webhooks and REST API key.' ) ) {
			e.preventDefault();
		}
	} );

	function refreshStats() {
		post( 'vio_stats' ).done( function ( r ) {
			if ( ! r || ! r.success ) { return; }
			var d = r.data;
			setMetric( 'total', d.total );
			setMetric( 'synced', d.synced );
			setMetric( 'sent', d.sent );
			setMetric( 'not-synced', d.not_synced );
		} );
	}

	function setMetric( key, val ) {
		var $v = $( '.vio-metric__value[data-key="' + key + '"]' );
		if ( ! $v.length || String( $v.text() ) === String( val ) ) { return; }
		$v.text( val ).removeClass( 'is-bump' );
		void $v[ 0 ].offsetWidth;
		$v.addClass( 'is-bump' );
	}

} )( jQuery );
