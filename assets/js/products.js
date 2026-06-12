/**
 * Vio bulk actions in the product list (sync / delete) with a live progress modal
 * that shows each product as it is processed.
 */
( function ( $ ) {
	'use strict';

	function esc( s ) { return $( '<div>' ).text( s == null ? '' : s ).html(); }

	var $modal = $(
		'<div id="vio-progress" class="vio-progress">' +
			'<div class="vio-progress__content">' +
				'<div class="vio-progress__head">' +
					'<span class="vio-progress__spinner"></span>' +
					'<span class="vio-progress__label"></span>' +
					'<span class="vio-progress__count">0 / 0</span>' +
				'</div>' +
				'<div class="vio-progress__bar"><div class="vio-progress__fill"></div></div>' +
				'<ul class="vio-progress__items"></ul>' +
			'</div>' +
		'</div>'
	);
	$( 'body' ).append( $modal );

	var $fill  = $modal.find( '.vio-progress__fill' );
	var $count = $modal.find( '.vio-progress__count' );
	var $label = $modal.find( '.vio-progress__label' );
	var $items = $modal.find( '.vio-progress__items' );

	// Title + thumbnail come straight from the product row already on the page.
	function rowInfo( id ) {
		var $row  = $( '#post-' + id );
		var title = $.trim( $row.find( '.row-title' ).first().text() ) || ( '#' + id );
		var thumb = $row.find( 'td.thumb img, .column-thumb img' ).first().attr( 'src' ) || '';
		return { id: id, title: title, thumb: thumb };
	}

	function chunk( arr, n ) {
		var out = [];
		for ( var i = 0; i < arr.length; i += n ) { out.push( arr.slice( i, i + n ) ); }
		return out;
	}

	$( document ).on( 'click', '#doaction, #doaction2', function ( e ) {
		var select = $( this ).attr( 'id' ) === 'doaction2' ? '#bulk-action-selector-bottom' : '#bulk-action-selector-top';
		var action = $( select ).val();

		if ( action !== 'vio_sync' && action !== 'vio_delete' ) {
			return;
		}
		e.preventDefault();

		var infos = $( 'input[name="post[]"]:checked' ).map( function () {
			return rowInfo( $( this ).val() );
		} ).get();

		if ( ! infos.length ) {
			return;
		}

		var total = infos.length, done = 0, i = 0, hadSuccess = false;
		var chunks = chunk( infos, 5 );

		$label.text( 'vio_delete' === action ? 'Deleting from Vio…' : 'Syncing products…' );
		$count.text( '0 / ' + total );
		$fill.css( 'width', '0%' );
		$items.empty();
		$modal.removeClass( 'is-done' ).addClass( 'is-visible' );

		function addRows( batch ) {
			var $rows = batch.map( function ( it ) {
				var thumb = it.thumb
					? '<img class="vio-progress__thumb" src="' + esc( it.thumb ) + '" alt="" />'
					: '<span class="vio-progress__thumb"></span>';
				var $li = $( '<li class="vio-progress__item is-active">' + thumb +
					'<span class="vio-progress__name">' + esc( it.title ) + '</span>' +
					'<span class="vio-progress__tick"></span></li>' );
				$items.prepend( $li );
				return $li;
			} );
			$items.children().slice( 8 ).remove(); // keep the panel compact
			return $rows;
		}

		function reloadSoon() { setTimeout( function () { window.location.reload(); }, 900 ); }

		function next() {
			if ( i >= chunks.length ) {
				$label.text( 'Done' );
				$count.text( total + ' / ' + total );
				$modal.addClass( 'is-done' );
				if ( 'vio_sync' === action && hadSuccess ) {
					$.post( vioWcSync.ajaxUrl, { action: 'vio_finish_sync', nonce: vioWcSync.nonce } ).always( reloadSoon );
				} else {
					reloadSoon();
				}
				return;
			}

			var batch = chunks[ i++ ];
			var ids   = batch.map( function ( it ) { return it.id; } );
			var $rows = addRows( batch );

			$.ajax( {
				url: vioWcSync.ajaxUrl,
				type: 'post',
				data: { action: action, nonce: vioWcSync.nonce, id_posts: ids }
			} )
				.done( function () { hadSuccess = true; } )
				.always( function () {
					done += batch.length;
					$fill.css( 'width', Math.round( ( done * 100 ) / total ) + '%' );
					$count.text( done + ' / ' + total );
					$rows.forEach( function ( $li ) { $li.removeClass( 'is-active' ).addClass( 'is-done' ); } );
					next();
				} );
		}

		next();
	} );
} )( jQuery );
