( function () {
	'use strict';
	// The review box sits inside WooCommerce's order form, so it cannot contain its own <form>
	// (nested forms are invalid HTML and would submit the order form instead). Use AJAX.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.wcctc-review button[data-decision]' ) : null;
		if ( ! btn || ! window.wcctcAdmin ) {
			return;
		}
		e.preventDefault();
		var box = btn.closest( '.wcctc-review' );
		var msg = box.querySelector( '.wcctc-review-msg' );
		var decision = btn.getAttribute( 'data-decision' );
		if ( decision === 'rejected' && ! window.confirm( window.wcctcAdmin.confirmReject ) ) {
			return;
		}
		var fd = new FormData();
		fd.append( 'action', 'wcctc_review' );
		fd.append( 'order_id', box.getAttribute( 'data-order' ) );
		fd.append( '_wpnonce', box.getAttribute( 'data-nonce' ) );
		fd.append( 'decision', decision );
		fd.append( 'note', box.querySelector( 'textarea' ).value );
		var notify = box.querySelector( 'input[name="notify"]' );
		fd.append( 'notify', notify && notify.checked ? '1' : '0' );
		box.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = true; } );
		fetch( window.wcctcAdmin.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				msg.textContent = ( res.data && res.data.message ) || '';
				if ( res.success ) {
					window.location.reload();
				} else {
					box.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = false; } );
				}
			} )
			.catch( function () {
				msg.textContent = window.wcctcAdmin.failed;
				box.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = false; } );
			} );
	} );
}() );
