/**
 * Silent previews in the video cards.
 *
 * A still grid of posters asks to be ignored; one moving frame does not. Done
 * naively that is six videos downloading at once, which is why PLAN.md ruled
 * it out for 0.1 — so it is built the only way that keeps the budget:
 *
 *   ONE video element exists on the page. Ever. It moves from card to card,
 *   plays five seconds, and moves on. Nothing downloads until a card's turn
 *   arrives, and turns only come round while the row is on screen. Hovering
 *   hands it over at once — the shopper saying where to spend the bandwidth.
 *
 * Loaded only where a card surface previews.
 */
( function () {
	'use strict';

	var SLOT = 5000;

	// Someone who asked for less motion, or is counting megabytes, gets the
	// posters and nothing else.
	if ( window.matchMedia && matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}

	var conn = navigator.connection;
	if ( conn && ( conn.saveData || /2g/.test( conn.effectiveType || '' ) ) ) {
		return;
	}

	var cards = [];
	var current = null;
	var timer = 0;
	var holding = false;
	var video = null;

	function element() {
		if ( video ) {
			return video;
		}

		video = document.createElement( 'video' );
		video.className = 'ocs-card__preview skip-lazy';
		video.muted = true;
		video.defaultMuted = true;
		video.loop = true;
		video.playsInline = true;
		video.preload = 'none';
		video.setAttribute( 'muted', '' );
		video.setAttribute( 'playsinline', '' );
		video.setAttribute( 'webkit-playsinline', 'true' );
		video.setAttribute( 'aria-hidden', 'true' );
		video.setAttribute( 'data-no-lazy', '1' );
		video.setAttribute( 'data-skip-lazy', '' );

		// The poster underneath shows until there is a frame worth showing.
		video.addEventListener( 'playing', function () {
			video.classList.add( 'is-on' );
		} );

		return video;
	}

	function park() {
		if ( ! video ) {
			return;
		}

		video.classList.remove( 'is-on' );
		video.pause();
		// Emptying the source is what actually stops the download.
		video.removeAttribute( 'src' );
		video.load();

		if ( video.parentNode ) {
			video.parentNode.removeChild( video );
		}

		if ( current ) {
			current.el.removeAttribute( 'data-ocs-playing' );
			current = null;
		}
	}

	function playCard( card ) {
		if ( ! card || card === current ) {
			return;
		}

		park();

		var frame = card.el.querySelector( '.ocs-card__frame' );
		if ( ! frame ) {
			return;
		}

		current = card;
		card.el.setAttribute( 'data-ocs-playing', '1' );

		var node = element();
		frame.appendChild( node );
		node.src = card.url;

		var started = node.play();
		if ( started && started.catch ) {
			started.catch( function ( error ) {
				// The poster is already there and correct, so stepping back is
				// always safe. What differs is whether to try again: a browser
				// refusing autoplay outright will refuse every five seconds
				// too, so that one ends the rotation. Everything else — the
				// power saver pausing background video, a slot that moved on
				// mid-load — is a moment, not a verdict.
				park();

				if ( error && 'NotAllowedError' === error.name ) {
					clearTimeout( timer );
					cards.length = 0;
				}
			} );
		}
	}

	function visible() {
		return cards.filter( function ( card ) {
			return card.visible;
		} );
	}

	function advance() {
		var pool = visible();

		if ( ! pool.length ) {
			park();
			return;
		}

		var at = current ? pool.indexOf( current ) : -1;
		playCard( pool[ ( at + 1 ) % pool.length ] );
	}

	function tick() {
		clearTimeout( timer );

		timer = setTimeout( function () {
			if ( ! holding ) {
				advance();
			}
			tick();
		}, SLOT );
	}

	function start() {
		if ( ! current && visible().length ) {
			advance();
		}
		tick();
	}

	// Declared before the observer that leans on it.
	/**
	 * On screen right now? Answers for the first turn, before the observer
	 * has spoken, and wherever it stays quiet.
	 *
	 * @param {Element} el Card.
	 * @return {boolean}
	 */
	function inView( el ) {
		var r = el.getBoundingClientRect();
		var h = window.innerHeight || document.documentElement.clientHeight;

		return r.bottom > 0 && r.top < h && r.width > 0;
	}

	var observer = window.IntersectionObserver
		? new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				var card = cards.filter( function ( c ) {
					return c.el === entry.target;
				} )[ 0 ];

				if ( card ) {
					// Either signal saying yes is enough. The observer is the
					// cheap one to listen to while scrolling, but a false
					// negative from it — a frame not yet painted, a transition
					// mid-flight — would otherwise switch the previews off for
					// good. `inView` already rejects anything with no box, so
					// display:none and friends still count as away.
					card.visible = entry.isIntersecting || inView( entry.target );
				}
			} );

			if ( current && ! current.visible ) {
				park();
			}

			if ( visible().length ) {
				start();
			} else {
				park();
				clearTimeout( timer );
			}
		}, { threshold: 0.5 } )
		: null;

	document.querySelectorAll( '[data-ocs-autoplay] [data-ocs-preview]' ).forEach( function ( el ) {
		var card = { el: el, url: el.getAttribute( 'data-ocs-preview' ), visible: false };
		cards.push( card );

		card.visible = inView( el );

		if ( observer ) {
			observer.observe( el );
		}

		// A pointer resting on a card is a request: honour it at once and stop
		// the clock until it leaves.
		el.addEventListener( 'pointerenter', function ( e ) {
			if ( 'touch' === e.pointerType ) {
				return;
			}
			holding = true;
			playCard( card );
			tick();
		} );

		el.addEventListener( 'pointerleave', function ( e ) {
			if ( 'touch' === e.pointerType ) {
				return;
			}
			holding = false;
			tick();
		} );
	} );

	if ( cards.length ) {
		start();

		// Without an observer, the rectangles are all we have.
		if ( ! observer ) {
			addEventListener( 'scroll', function () {
				cards.forEach( function ( card ) {
					card.visible = inView( card.el );
				} );
			}, { passive: true } );
		}
	}

	// A hidden tab should not be playing video at all.
	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			park();
			clearTimeout( timer );
		} else if ( cards.length ) {
			start();
		}
	} );
}() );
