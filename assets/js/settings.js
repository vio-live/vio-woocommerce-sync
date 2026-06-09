/**
 * Vio settings screen.
 */
( function ( $ ) {
	'use strict';

	// Go to the product list.
	$( document ).on( 'click', '#sync-all-button', function () {
		// The href already points to the list; this handler is kept for compatibility.
	} );

	// Custom currency selector (optional; the settings use WC's native select).
	$( document ).on( 'click', '.vio-select .default_option', function () {
		$( this ).closest( '.vio-select__wrap' ).toggleClass( 'active' );
	} );

	$( document ).on( 'click', '.vio-select .select_ul li', function () {
		var $wrap = $( this ).closest( '.vio-select__wrap' );
		$wrap.find( '.default_option li' ).html( $( this ).html() );
		$wrap.removeClass( 'active' );
	} );

	// Currency save via AJAX (used only with the custom selector).
	$( document ).on( 'click', '#vio-save-currency', function ( e ) {
		e.preventDefault();
		$( this ).prop( 'disabled', true );

		$.post( vioWcSync.ajaxUrl, {
			action: 'vio_save_settings',
			nonce: vioWcSync.nonce,
			currency: $.trim( $( '.vio-select .default_option .option' ).text() )
		} ).always( function () {
			window.location.reload();
		} );
	} );
} )( jQuery );
