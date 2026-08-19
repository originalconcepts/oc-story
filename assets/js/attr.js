/**
 * Carries a story tap into the add-to-cart form.
 *
 * The player wrote the tap to sessionStorage; this copies it into the cart
 * form as a hidden field, where the server revalidates every part of it. The
 * page's own markup is never varied — a shopper who arrived without a tap
 * costs this script one storage read and nothing else.
 *
 * Product pages only, deferred, ~400 bytes.
 */
( function () {
	'use strict';

	var raw;

	try {
		raw = sessionStorage.getItem( 'ocs_attr' );
	} catch ( e ) {
		return;
	}

	if ( ! raw ) {
		return;
	}

	var attr;

	try {
		attr = JSON.parse( raw );
	} catch ( e ) {
		return;
	}

	var windowMs = ( window.ocsAttrCfg && window.ocsAttrCfg.window ) || 604800000;

	if ( ! attr || ! attr.ts || Date.now() - attr.ts > windowMs ) {
		return;
	}

	var attach = function () {
		document.querySelectorAll( 'form.cart' ).forEach( function ( form ) {
			if ( form.querySelector( 'input[name="ocs_attr"]' ) ) {
				return;
			}

			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'ocs_attr';
			input.value = raw;
			form.appendChild( input );
		} );
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', attach );
	} else {
		attach();
	}
}() );
