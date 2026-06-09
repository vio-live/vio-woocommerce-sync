/**
 * Pantalla de ajustes de Vio.
 */
( function ( $ ) {
	'use strict';

	// Ir a la lista de productos.
	$( document ).on( 'click', '#sync-all-button', function () {
		// El href ya apunta a la lista; este handler queda por compatibilidad.
	} );

	// Selector de moneda personalizado (opcional; los ajustes usan el select nativo de WC).
	$( document ).on( 'click', '.vio-select .default_option', function () {
		$( this ).closest( '.vio-select__wrap' ).toggleClass( 'active' );
	} );

	$( document ).on( 'click', '.vio-select .select_ul li', function () {
		var $wrap = $( this ).closest( '.vio-select__wrap' );
		$wrap.find( '.default_option li' ).html( $( this ).html() );
		$wrap.removeClass( 'active' );
	} );

	// Guardado de moneda vía AJAX (si se usa el selector personalizado).
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
