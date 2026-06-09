/**
 * Bulk actions de Vio en la lista de productos (sync / delete) con modal de progreso.
 */
( function ( $ ) {
	'use strict';

	var $modal = $(
		'<div id="vio-progress" class="vio-progress">' +
			'<div class="vio-progress__content">' +
				'<div class="vio-progress__logo"></div>' +
				'<div class="vio-progress__bar"><div class="vio-progress__fill"></div></div>' +
				'<div class="vio-progress__info">' +
					'<p>Procesados: <strong class="vio-progress__done">0</strong></p>' +
					'<p>Total: <strong class="vio-progress__total">0</strong></p>' +
				'</div>' +
			'</div>' +
		'</div>'
	);
	$( 'body' ).append( $modal );

	$( document ).on( 'click', '#doaction, #doaction2', function ( e ) {
		var select = $( this ).attr( 'id' ) === 'doaction2' ? '#bulk-action-selector-bottom' : '#bulk-action-selector-top';
		var action = $( select ).val();

		if ( action !== 'vio_sync' && action !== 'vio_delete' ) {
			return;
		}
		e.preventDefault();

		var ids = $( 'input[name="post[]"]:checked' ).map( function () {
			return $( this ).val();
		} ).get();

		if ( ! ids.length ) {
			return;
		}

		var total = ids.length;
		var processed = 0;
		var hadSuccess = false;
		var chunkSize = 5;
		var chunks = [];
		for ( var i = 0; i < ids.length; i += chunkSize ) {
			chunks.push( ids.slice( i, i + chunkSize ) );
		}
		var pending = chunks.length;

		$( '.vio-progress__total', $modal ).text( total );
		$( '.vio-progress__done', $modal ).text( '0' );
		$( '.vio-progress__fill', $modal ).css( 'width', '0%' );
		$modal.addClass( 'is-visible' );

		$.each( chunks, function ( index, chunk ) {
			$.ajax( {
				url: vioWcSync.ajaxUrl,
				type: 'post',
				data: { action: action, nonce: vioWcSync.nonce, id_posts: chunk }
			} )
				.done( function () {
					hadSuccess = true;
				} )
				.always( function () {
					processed += chunk.length;
					$( '.vio-progress__done', $modal ).text( processed );
					$( '.vio-progress__fill', $modal ).css( 'width', ( ( processed * 100 ) / total ) + '%' );

					pending -= 1;
					if ( pending > 0 ) {
						return;
					}

					if ( action === 'vio_sync' && hadSuccess ) {
						$.post( vioWcSync.ajaxUrl, { action: 'vio_finish_sync', nonce: vioWcSync.nonce } )
							.always( function () {
								window.location.reload();
							} );
					} else {
						window.location.reload();
					}
				} );
		} );
	} );
} )( jQuery );
