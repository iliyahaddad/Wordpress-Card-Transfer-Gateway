/* Classic checkout: pre-upload the receipt via REST (file inputs are not sent by WooCommerce's AJAX checkout). */
( function ( $ ) {
	'use strict';
	if ( ! window.wcctcCheckout || ! window.fetch ) {
		return;
	}
	var cfg = window.wcctcCheckout;
	var state = { token: '', name: '' };

	function setStatus( msg, isError ) {
		$( '.wcctc-upload-status' ).text( msg || '' ).toggleClass( 'wcctc-error', !! isError );
	}

	// The payment section is re-rendered on "updated_checkout"; put the token back.
	function restore() {
		var $t = $( '#wcctc-receipt-token' );
		if ( $t.length ) {
			$t.val( state.token );
		}
		setStatus( state.token ? cfg.i18n.uploaded + ' ' + state.name : '', false );
	}

	function reset( message ) {
		state.token = '';
		state.name = '';
		$( '#wcctc-receipt-token' ).val( '' );
		setStatus( message, true );
	}

	$( document.body ).on( 'change', '#wcctc-receipt', function () {
		var f = this.files && this.files[ 0 ];
		if ( ! f ) {
			return;
		}
		if ( f.size > cfg.maxBytes ) {
			reset( cfg.i18n.tooLarge );
			this.value = '';
			return;
		}
		var fd = new FormData();
		fd.append( 'receipt', f );
		setStatus( cfg.i18n.uploading, false );
		fetch( cfg.restUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( r ) {
				return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } );
			} )
			.then( function ( res ) {
				if ( res.ok && res.j.token ) {
					state.token = res.j.token;
					state.name = f.name;
					$( '#wcctc-receipt-token' ).val( state.token );
					setStatus( cfg.i18n.uploaded + ' ' + f.name, false );
				} else {
					reset( ( res.j && res.j.message ) || cfg.i18n.failed );
				}
			} )
			.catch( function () { reset( cfg.i18n.failed ); } );
	} );

	$( document.body ).on( 'updated_checkout payment_method_selected', restore );
}( jQuery ) );
