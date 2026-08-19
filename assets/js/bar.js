/**
 * The circles bar.
 *
 * This is the only script the storefront loads, and it does almost nothing: it
 * waits for a tap and then imports the player. Everything expensive — the
 * player, its stylesheet, and any video at all — is on the far side of that
 * first interaction, which is what keeps a page carrying this plugin
 * indistinguishable from one that is not.
 *
 * Written as a classic deferred script rather than a module. `import()` works
 * in both, and a classic script is one fewer thing for an optimisation plugin
 * to rewrite incorrectly.
 */
( function () {
	'use strict';

	var cfg = window.ocsCfg || {};
	var chunk = null;
	var payloads = {};
	var SEEN = 'ocs_seen';

	function barOf( node ) {
		return node.closest ? node.closest( '[data-ocs-bar]' ) : null;
	}

	/**
	 * The stories for one bar: inline when the server judged it small enough to
	 * be free, fetched otherwise. Either way it is resolved once and reused.
	 */
	function payload( bar ) {
		var key = bar.getAttribute( 'data-ocs-bar' );

		if ( ! payloads[ key ] ) {
			var inline = document.getElementById( 'ocs-data-' + key );

			payloads[ key ] = inline
				? Promise.resolve( JSON.parse( inline.textContent ) )
				: fetch( bar.getAttribute( 'data-ocs-src' ), { credentials: 'omit' } ).then( function ( r ) {
					return r.json();
				} );
		}

		return payloads[ key ];
	}

	/**
	 * Start loading everything the tap is about to need.
	 *
	 * Called on `pointerdown`, which fires a hundred or so milliseconds before
	 * the click that follows it. By the time the finger lifts, the player chunk
	 * and the stylesheet are usually already there, so the overlay opens on the
	 * same frame as the release.
	 */
	function warm( bar ) {
		if ( ! bar ) {
			return;
		}

		if ( ! chunk ) {
			var link = document.createElement( 'link' );
			link.rel = 'stylesheet';
			link.href = cfg.css;
			document.head.appendChild( link );

			// Resolved against the page rather than passed through as given.
			// `import()` treats anything that is not absolute or explicitly
			// relative as a bare module specifier and refuses it, so a URL that
			// looks perfectly ordinary in the page source can fail here — and a
			// CDN rewrite or a filter on OCS_URL is all it takes.
			chunk = import( new URL( cfg.player, document.baseURI ).href ).catch( function ( e ) {
				// Deliberately loud. This is the one failure that makes every
				// circle on the page do nothing, and it must not be silent.
				if ( window.console ) {
					console.error( 'OC Story: the player could not be loaded from ' + cfg.player, e );
				}
				chunk = null;
				throw e;
			} );
		}

		payload( bar );
	}

	function seen() {
		try {
			return JSON.parse( localStorage.getItem( SEEN ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function markSeen( id ) {
		try {
			var all = seen();
			all[ id ] = 1;
			localStorage.setItem( SEEN, JSON.stringify( all ) );
		} catch ( e ) {}

		document.querySelectorAll( '[data-ocs-open="' + id + '"]' ).forEach( function ( node ) {
			node.setAttribute( 'data-ocs-seen', '1' );
		} );
	}

	/**
	 * Grey the rings of stories already watched.
	 *
	 * Deferred to idle time on purpose: it reads localStorage and touches the
	 * DOM, and neither is worth doing before the page has finished painting.
	 */
	function paintSeen() {
		var all = seen();

		Object.keys( all ).forEach( function ( id ) {
			document.querySelectorAll( '[data-ocs-open="' + id + '"]' ).forEach( function ( node ) {
				node.setAttribute( 'data-ocs-seen', '1' );
			} );
		} );
	}

	function openFrom( button ) {
		var bar = barOf( button );

		if ( ! bar ) {
			return;
		}

		warm( bar );

		var id = parseInt( button.getAttribute( 'data-ocs-open' ), 10 );

		Promise.all( [ chunk, payload( bar ) ] ).then( function ( parts ) {
			var stories = parts[ 1 ] || [];
			var index = 0;

			for ( var i = 0; i < stories.length; i++ ) {
				if ( stories[ i ].i === id ) {
					index = i;
					break;
				}
			}

			parts[ 0 ].open( stories, index, {
				cfg: cfg,
				onSeen: markSeen,
			} );
		} ).catch( function () {
			// A failed player must not break the page it is sitting on. The
			// circle does nothing, the shop keeps selling, and warm() has
			// already said why in the console.
		} );
	}

	document.addEventListener( 'pointerdown', function ( e ) {
		var button = e.target.closest && e.target.closest( '[data-ocs-open]' );

		if ( button ) {
			warm( barOf( button ) );
		}
	}, { passive: true } );

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest && e.target.closest( '[data-ocs-open]' );

		if ( button ) {
			e.preventDefault();
			openFrom( button );
		}
	} );

	if ( window.requestIdleCallback ) {
		requestIdleCallback( paintSeen );
	} else {
		setTimeout( paintSeen, 400 );
	}
}() );
