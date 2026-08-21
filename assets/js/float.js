/**
 * The corner video: whether to appear at all, and when to start.
 *
 * Every other surface in this plugin costs nothing until someone taps it.
 * This one plays by itself, in front of the page, uninvited — so it owes the
 * visitor three things, and this file is those three things.
 *
 * It waits. Nothing here runs until the page has finished loading what the
 * shop actually came to show; a corner video that competes with the hero for
 * bandwidth is a corner video that made the shop slower.
 *
 * It can be dismissed. And once dismissed it stays gone for a week, across
 * pages — the reason people install blockers is things that come back.
 *
 * It stops when it is not being looked at. Off screen, hidden tab, or a
 * visitor who has asked for less motion: paused.
 */

( function () {
	var HIDDEN = 'ocs_float_hidden';
	var WEEK = 7 * 24 * 60 * 60 * 1000;

	var box = document.querySelector( '.ocs-float' );

	if ( ! box ) {
		return;
	}

	/**
	 * Whether this corner was waved away recently.
	 *
	 * @return {boolean} True while the dismissal still stands.
	 */
	function dismissed() {
		try {
			var until = parseInt( localStorage.getItem( HIDDEN ), 10 );

			return until && until > Date.now();
		} catch ( e ) {
			return false;
		}
	}

	if ( dismissed() ) {
		box.remove();
		return;
	}

	box.hidden = false;

	var video = null;
	var calm = window.matchMedia && matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/**
	 * Build the video the first time it is wanted, never before.
	 *
	 * The poster is already on screen by then, so this adds motion to
	 * something that is already the right size — nothing moves, nothing
	 * reflows.
	 */
	function attach() {
		var src = box.getAttribute( 'data-ocs-float-src' );

		if ( video || ! src || calm ) {
			return;
		}

		video = document.createElement( 'video' );
		video.className = 'ocs-float__video';
		video.muted = true;
		video.loop = true;
		video.playsInline = true;
		video.setAttribute( 'muted', '' );
		video.setAttribute( 'playsinline', '' );
		video.preload = 'auto';
		video.src = src;

		// The play mark goes only once the video is really running. A triangle
		// over moving footage is a lie, and hiding it the moment the element
		// exists is a different lie — autoplay can still be refused.
		video.addEventListener( 'playing', function () {
			box.classList.add( 'is-playing' );
		} );

		video.addEventListener( 'pause', function () {
			box.classList.remove( 'is-playing' );
		} );

		box.querySelector( '.ocs-float__open' ).append( video );

		play();
	}

	function play() {
		if ( ! video ) {
			return;
		}

		var promise = video.play();

		if ( promise && promise.catch ) {
			// A browser that refuses to autoplay even a silent video is a
			// browser telling us its owner's preference. The poster stays,
			// the play mark stays, and the thing is still tappable.
			promise.catch( function () {} );
		}
	}

	function pause() {
		if ( video && ! video.paused ) {
			video.pause();
		}
	}

	/** Whether the corner is on screen at all. */
	function seen() {
		var rect = box.getBoundingClientRect();

		return rect.bottom > 0 && rect.top < ( window.innerHeight || 0 );
	}

	function decide() {
		if ( document.hidden || ! seen() ) {
			pause();
			return;
		}

		attach();
		play();
	}

	box.querySelector( '[data-ocs-float-close]' ).addEventListener( 'click', function ( e ) {
		e.stopPropagation();

		try {
			localStorage.setItem( HIDDEN, String( Date.now() + WEEK ) );
		} catch ( err ) {}

		pause();
		box.remove();
	} );

	document.addEventListener( 'visibilitychange', decide );
	addEventListener( 'scroll', decide, { passive: true } );
	addEventListener( 'resize', decide, { passive: true } );

	// After everything else. `load` rather than `DOMContentLoaded`, and then
	// one more beat, because the point is to be last.
	function begin() {
		if ( window.requestIdleCallback ) {
			requestIdleCallback( decide, { timeout: 2000 } );
			return;
		}

		setTimeout( decide, 600 );
	}

	if ( 'complete' === document.readyState ) {
		begin();
	} else {
		addEventListener( 'load', begin );
	}
}() );
