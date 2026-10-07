( function () {
	'use strict';
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var el = window.wp && window.wp.element;
	if ( ! registry || ! settingsApi || ! el ) {
		return;
	}
	var decode = window.wp.htmlEntities.decodeEntities;
	var h = el.createElement;
	var useState = el.useState;
	var useEffect = el.useEffect;
	var useCallback = el.useCallback;

	function Content( props ) {
		var data = props.data;
		function t( key, fallback ) {
			return ( data.i18n && data.i18n[ key ] ) || fallback;
		}
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var st = {
			reference: useState( '' ),
			name: useState( '' ),
			last4: useState( '' ),
			token: useState( '' ),
			status: useState( '' ),
			error: useState( false ),
		};
		var reference = st.reference[ 0 ], name = st.name[ 0 ], last4 = st.last4[ 0 ], token = st.token[ 0 ];

		// Both props are undefined inside the block editor preview.
		var onPaymentSetup = eventRegistration && eventRegistration.onPaymentSetup;
		var types = emitResponse && emitResponse.responseTypes;

		useEffect( function () {
			if ( ! onPaymentSetup || ! types ) {
				return undefined;
			}
			return onPaymentSetup( function () {
				if ( data.mode === 'stripe' ) {
					return { type: types.SUCCESS };
				}
				function fail( msg ) {
					return { type: types.ERROR, message: msg };
				}
				if ( data.referenceRequired && ! reference.trim() ) {
					return fail( t( 'refRequired', 'Please enter the bank transfer reference.' ) );
				}
				if ( data.senderLast4 && last4 && ! /^[0-9\u06F0-\u06F9\u0660-\u0669]{4}$/.test( last4 ) ) {
					return fail( t( 'last4Invalid', 'Sender card last 4 digits must be exactly four digits.' ) );
				}
				if ( data.receiptRequired && ! token ) {
					return fail( t( 'receiptRequired', 'Please upload the transfer receipt.' ) );
				}
				return {
					type: types.SUCCESS,
					meta: {
						paymentMethodData: {
							wcctc_reference: reference,
							wcctc_sender_name: name,
							wcctc_sender_last4: last4,
							wcctc_receipt_token: token,
						},
					},
				};
			} );
		}, [ onPaymentSetup, types, data, reference, name, last4, token ] );

		var onFile = useCallback( function ( e ) {
			var f = e.target.files && e.target.files[ 0 ];
			if ( ! f ) {
				return;
			}
			if ( f.size > data.maxBytes ) {
				st.token[ 1 ]( '' );
				st.error[ 1 ]( true );
				st.status[ 1 ]( t( 'tooLarge', 'Receipt must be 5 MB or smaller.' ) );
				return;
			}
			var fd = new FormData();
			fd.append( 'receipt', f );
			st.error[ 1 ]( false );
			st.status[ 1 ]( t( 'uploading', 'Uploading…' ) );
			fetch( data.restUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-WP-Nonce': data.nonce } } )
				.then( function ( r ) {
					return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } );
				} )
				.then( function ( res ) {
					if ( res.ok && res.j.token ) {
						st.token[ 1 ]( res.j.token );
						st.status[ 1 ]( t( 'uploaded', 'Receipt attached:' ) + ' ' + f.name );
					} else {
						st.token[ 1 ]( '' );
						st.error[ 1 ]( true );
						st.status[ 1 ]( ( res.j && res.j.message ) || t( 'failed', 'Receipt upload failed.' ) );
					}
				} )
				.catch( function () {
					st.token[ 1 ]( '' );
					st.error[ 1 ]( true );
					st.status[ 1 ]( t( 'failed', 'Receipt upload failed.' ) );
				} );
		}, [ data ] );

		var children = [];
		if ( data.description ) {
			children.push( h( 'p', { key: 'd' }, decode( data.description ) ) );
		}
		if ( data.mode === 'stripe' ) {
			children.push( h( 'p', { key: 's', className: 'wcctc-note' }, t( 'stripeNote', 'You will be redirected to Stripe to complete the payment securely.' ) ) );
			return h( 'div', { className: 'wcctc-payment-box' }, children );
		}

		var rows = Object.keys( data.rows || {} ).map( function ( label ) {
			return h( 'p', { key: label }, h( 'span', null, label + ' ' ), h( 'bdi', { className: 'wcctc-value' }, data.rows[ label ] ) );
		} );
		children.push( h( 'div', { key: 'r', className: 'wcctc-recipient' }, h( 'strong', null, t( 'destination', 'Transfer destination' ) ), rows ) );
		if ( data.instructions ) {
			children.push( h( 'div', { key: 'i', className: 'wcctc-instructions', dangerouslySetInnerHTML: { __html: data.instructions } } ) );
		}
		function field( id, label, value, setter, extra ) {
			return h( 'p', { key: id, className: 'wcctc-field' },
				h( 'label', { htmlFor: id }, label ),
				h( 'input', Object.assign( { id: id, type: 'text', className: 'input-text', value: value, onChange: function ( e ) { setter( e.target.value ); } }, extra || {} ) )
			);
		}
		children.push( field( 'wcctc-reference', decode( data.referenceLabel ) + ( data.referenceRequired ? ' *' : '' ), reference, st.reference[ 1 ], { maxLength: 120, dir: 'ltr', autoComplete: 'off' } ) );
		if ( data.referenceHelp ) {
			children.push( h( 'small', { key: 'rh' }, decode( data.referenceHelp ) ) );
		}
		if ( data.senderName ) {
			children.push( field( 'wcctc-sender-name', t( 'senderName', 'Sender name' ), name, st.name[ 1 ], { maxLength: 120 } ) );
		}
		if ( data.senderLast4 ) {
			children.push( field( 'wcctc-sender-last4', t( 'last4Label', 'Last 4 digits of the sender card' ), last4, st.last4[ 1 ], { maxLength: 4, inputMode: 'numeric', dir: 'ltr', autoComplete: 'off' } ) );
		}
		children.push( h( 'p', { key: 'f', className: 'wcctc-field' },
			h( 'label', { htmlFor: 'wcctc-receipt' }, t( 'receiptLabel', 'Transfer receipt' ) + ( data.receiptRequired ? ' *' : '' ) ),
			h( 'input', { id: 'wcctc-receipt', type: 'file', accept: 'image/jpeg,image/png,image/webp,application/pdf', onChange: onFile } ),
			h( 'small', { className: 'wcctc-upload-status' + ( st.error[ 0 ] ? ' wcctc-error' : '' ), role: 'status', 'aria-live': 'polite' }, st.status[ 0 ] )
		) );
		return h( 'div', { className: 'wcctc-payment-box' }, children );
	}

	function register( name ) {
		var data = settingsApi.getSetting( name + '_data', null );
		if ( ! data ) {
			return;
		}
		var label = decode( data.title || name );
		registry.registerPaymentMethod( {
			name: name,
			label: h( 'span', null, label ),
			ariaLabel: label,
			content: h( Content, { data: data } ),
			edit: h( Content, { data: data } ),
			canMakePayment: function ( args ) {
				var allowed = data.allowedCurrencies || [];
				var cur = args && args.cartTotals && args.cartTotals.currency_code;
				return ! allowed.length || ! cur || allowed.indexOf( String( cur ).toUpperCase() ) !== -1;
			},
			supports: data.supports || { features: [ 'products' ] },
		} );
	}

	register( 'wcctc_iran' );
	register( 'wcctc_international' );
}() );
