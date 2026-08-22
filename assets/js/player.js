/**
 * The story player.
 *
 * Imported by bar.js on the first tap and never before, which is why it is
 * allowed to be the largest thing here. It still stays inside its budget by
 * refusing to preload: the slide being watched loads fully, the next one gets
 * its metadata, and nothing else is touched until it is reached.
 */

const RTL = () => 'rtl' === ( document.documentElement.getAttribute( 'dir' ) || '' ).toLowerCase();

/**
 * Hand an event to the bar's queue; the bar owns sending. If the queue is not
 * there, analytics is off and the event simply evaporates.
 *
 * @param {string} type  Event type.
 * @param {Object} extra Extra fields.
 */
function track( type, extra ) {
	const queue = window.__ocsQ;

	if ( queue && state ) {
		queue.push( Object.assign( { t: type, s: story().i, f: state.surface, b: state.bar }, extra || {} ) );
	}
}

let ui = null;
let state = null;
// Whether the last thing the viewer did was ask for this story again. Lives
// up here with the rest of the player's memory because `go()` reads it, and
// `go()` runs from a keypress that can arrive before the file has finished
// evaluating.
let rewound = false;

/* ------------------------------------------------------------------ build */

function el( tag, cls, attrs ) {
	const node = document.createElement( tag );

	if ( cls ) {
		node.className = cls;
	}

	for ( const [ key, value ] of Object.entries( attrs || {} ) ) {
		if ( 'text' === key ) {
			node.textContent = value;
		} else if ( null !== value && undefined !== value && false !== value ) {
			node.setAttribute( key, value );
		}
	}

	return node;
}

function build( cfg ) {
	const i18n = cfg.i18n || {};

	const root = el( 'div', 'ocsp', { role: 'dialog', 'aria-modal': 'true' } );

	if ( cfg.dim ) {
		root.classList.add( 'ocsp--dim' );
	}
	const stage = el( 'div', 'ocsp__stage' );

	// The soft-focus backdrop that fills the frame when the media itself
	// cannot — the Instagram treatment for landscape footage in a portrait
	// stage. It reuses the slide poster, which is already loaded.
	const blur = el( 'img', 'ocsp__blur', { alt: '', 'aria-hidden': 'true' } );
	blur.hidden = true;

	const image = el( 'img', 'ocsp__image', { alt: '' } );
	image.hidden = true;

	const video = el( 'video', 'ocsp__video', {
		playsinline: 'playsinline',
		'webkit-playsinline': 'true',
		preload: 'auto',
		'data-no-lazy': '1',
		'data-skip-lazy': '',
	} );
	video.classList.add( 'skip-lazy' );

	const bars = el( 'div', 'ocsp__bars' );
	const title = el( 'div', 'ocsp__title' );
	const close = el( 'button', 'ocsp__btn', { type: 'button', 'aria-label': i18n.close || 'Close', text: '✕' } );
	const unmute = el( 'button', 'ocsp__btn ocsp__unmute', { type: 'button', 'aria-label': 'Sound', text: '🔊' } );

	const top = el( 'div', 'ocsp__top' );
	top.append( title, close );

	const prev = el( 'button', 'ocsp__zone ocsp__zone--prev', { type: 'button', 'aria-label': i18n.prev || 'Previous' } );
	const next = el( 'button', 'ocsp__zone ocsp__zone--next', { type: 'button', 'aria-label': i18n.next || 'Next' } );

	const products = el( 'div', 'ocsp__products' );

	// The strip lives in its own layer so the arrows can sit at its edges
	// without joining the scroll they control.
	const strip = el( 'div', 'ocsp__strip' );
	// Named and placed by physical side, not logical. They move a scrollbar,
	// and scrollLeft is physical in every direction — an arrow that scrolls
	// left has to sit on the left and point left, in Hebrew as in English.
	const stripBack = el( 'button', 'ocsp__strip-nav ocsp__strip-nav--l', { type: 'button', 'aria-label': i18n.prev || 'Previous' } );
	const stripFwd = el( 'button', 'ocsp__strip-nav ocsp__strip-nav--r', { type: 'button', 'aria-label': i18n.next || 'Next' } );
	strip.append( products, stripBack, stripFwd );
	const pins = el( 'div', 'ocsp__pins' );

	// Metadata only, and only ever for the slide immediately after this one.
	const ahead = el( 'video', '', { preload: 'metadata' } );
	ahead.style.display = 'none';

	// The variations sheet, hidden until a Buy needs choices.
	const toast = el( 'div', 'ocsp__toast' );
	toast.hidden = true;

	const sheet = el( 'div', 'ocsp__sheet' );
	sheet.hidden = true;
	const sheetTitle = el( 'b', 'ocsp__sheet-title' );
	const sheetClose = el( 'button', 'ocsp__sheet-x', { type: 'button', 'aria-label': i18n.close || 'Close', text: '✕' } );
	const sheetHead = el( 'div', 'ocsp__sheet-head' );
	sheetHead.append( sheetTitle, sheetClose );
	const sheetBody = el( 'div', 'ocsp__sheet-body' );
	const sheetPrice = el( 'span', 'ocsp__sheet-price' );
	const sheetAdd = el( 'button', 'ocsp__sheet-add', { type: 'button' } );
	const sheetFoot = el( 'div', 'ocsp__sheet-foot' );
	sheetFoot.append( sheetPrice, sheetAdd );
	sheet.append( sheetHead, sheetBody, sheetFoot );

	// Outside the frame, centred: up and down move between galleries. Sits
	// before the stage in the DOM so direction places it — the right in an
	// RTL shop, mirrored in an LTR one. Hidden on phones, where the video
	// already fills the screen and a swipe does the same job.
	const rail = el( 'div', 'ocsp__rail ocsp__rail--' + ( cfg.nav || 'arrows' ) );
	const railUp = el( 'button', 'ocsp__rail-btn', { type: 'button', 'aria-label': i18n.prevGallery || 'Previous' } );
	const railDown = el( 'button', 'ocsp__rail-btn', { type: 'button', 'aria-label': i18n.nextGallery || 'Next' } );
	railUp.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>';
	railDown.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
	// The other way to move between galleries: their posters, stacked, with
	// the one you are watching lit. Filled once the payload is known.
	const thumbs = el( 'div', 'ocsp__thumbs' );

	rail.append( railUp, thumbs, railDown );

	// Two reactions, side by side on purpose. The heart is the one everybody
	// already knows, and it is what teaches the spark next to it that this
	// row is for reacting at all — George tapped the spark alone and asked
	// what it had done.
	const reactions = el( 'div', 'ocsp__reactions' );

	const like = el( 'button', 'ocsp__react ocsp__react--like', { type: 'button', 'aria-label': i18n.like || 'Like' } );
	like.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5l-1.5-1.35C5.4 14.5 2.5 11.9 2.5 8.6 2.5 6 4.5 4 7.1 4c1.5 0 2.9.7 3.8 1.8l1.1 1.3 1.1-1.3C14 4.7 15.4 4 16.9 4 19.5 4 21.5 6 21.5 8.6c0 3.3-2.9 5.9-8 10.55z"/></svg><b class="ocsp__react-count"></b>';

	const spark = el( 'button', 'ocsp__react ocsp__react--spark', { type: 'button', 'aria-label': i18n.spark || 'Spark' } );
	spark.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.2 6.1L20 10l-5.8 1.9L12 18l-2.2-6.1L4 10l5.8-1.9z"/></svg><b class="ocsp__react-count"></b>';

	// Said once, the first time anyone opens a player on this device.
	const hint = el( 'span', 'ocsp__hint', { text: i18n.sparkHint || '' } );
	hint.hidden = true;

	reactions.append( like, spark, hint );

	stage.append( blur, image, video, pins, bars, top, unmute, prev, next, reactions, strip, ahead, toast, sheet );
	root.append( rail, stage );

	return { root, stage, blur, image, video, ahead, bars, title, close, unmute, prev, next, strip, products, stripBack, stripFwd, pins, toast, sheet, sheetTitle, sheetClose, sheetBody, sheetPrice, sheetAdd, rail, railUp, railDown, thumbs, reactions, like, spark, hint };
}

/* ------------------------------------------------------------------ paint */

function story() {
	return state.stories[ state.si ];
}

function isImage() {
	const current = slide();
	return !! current && 'i' === current.ty;
}

/* An image has no currentTime, so it gets a clock: elapsed seconds that the
   hold-to-pause gesture and the sheet can stop and resume like a video. */
function clockNow() {
	const clock = state.clock;
	return clock.running ? clock.elapsed + ( performance.now() - clock.start ) / 1000 : clock.elapsed;
}

function clockPause() {
	if ( state && state.clock.running ) {
		state.clock.elapsed = clockNow();
		state.clock.running = false;
	}
}

function clockResume() {
	if ( state && ! state.clock.running ) {
		state.clock.start = performance.now();
		state.clock.running = true;
	}
}

function pausePlayback() {
	if ( isImage() ) {
		clockPause();
	} else {
		ui.video.pause();
	}
}

function resumePlayback() {
	if ( isImage() ) {
		clockResume();
	} else {
		ui.video.play().catch( () => {} );
	}
}

function slide() {
	const current = story();
	return current && current.s ? current.s[ state.qi ] : null;
}

function paintMute() {
	ui.unmute.textContent = state.muted ? '🔇' : '🔊';
}

function paintBars() {
	const count = story().s.length;

	if ( ui.bars.childElementCount !== count ) {
		ui.bars.replaceChildren(
			...Array.from( { length: count }, () => {
				const bar = el( 'div', 'ocsp__bar' );
				bar.append( el( 'i' ) );
				return bar;
			} )
		);
	}

	Array.from( ui.bars.children ).forEach( ( bar, i ) => {
		bar.firstChild.style.width = i < state.qi ? '100%' : '0';
	} );
}

function paintProducts() {
	const current = slide();
	const list = ( current && current.pr ) || [];

	// With several products the cards take a fixed share of the width, so the
	// next one is always partly in frame — a sliver of card is what tells a
	// thumb this row scrolls. A lone card just fits its content.
	ui.products.classList.toggle( 'ocsp__products--multi', list.length > 1 );

	ui.products.replaceChildren(
		...list.map( ( product ) => {
			// draggable=false on both: a card is an <a> and a thumbnail is an
			// <img>, and the browser's own link/image drag starts the moment
			// the mouse moves — cancelling the pointer stream the strip needs
			// to pan. That is why dragging the row did nothing.
			const card = el( 'a', 'ocsp__product', { href: product.u, 'data-pid': product.i, draggable: 'false' } );

			if ( product.t ) {
				card.append( el( 'img', 'ocsp__product-thumb', { src: product.t, alt: '', loading: 'lazy', draggable: 'false' } ) );
			}

			const info = el( 'span', 'ocsp__product-info' );
			info.append( el( 'b', '', { text: product.n } ) );

			// OC Reviews keeps WooCommerce's aggregates fresh; the card only
			// shows stars a product has actually earned.
			if ( product.c > 0 ) {
				const stars = el( 'span', 'ocsp__stars' );
				const base = el( 'span', 'ocsp__stars-base', { text: '★★★★★', 'aria-hidden': 'true' } );
				base.append( el( 'span', 'ocsp__stars-fill', { text: '★★★★★', style: 'width:' + Math.min( 100, ( product.r / 5 ) * 100 ) + '%' } ) );
				stars.append( base, el( 'span', 'ocsp__stars-count', { text: '(' + product.c + ')' } ) );
				info.append( stars );
			}

			// Sold out is said on the card, in red, before a tap is spent
			// finding out. The price stays: what it costs when it comes back
			// is still worth knowing.
			const gone = 0 === product.s;

			info.append(
				el( 'span', 'ocsp__product-price' + ( gone ? ' is-gone' : '' ), { text: product.p } )
			);

			if ( gone ) {
				info.append( el( 'span', 'ocsp__product-gone', { text: ( state.cfg.i18n && state.cfg.i18n.soldOut ) || '' } ) );
			}

			// A word, or just a plus. Both do the same thing; the plus is for
			// a shop whose cards are already crowded, and it keeps its label
			// for anyone listening rather than looking.
			const plus = 'plus' === state.cfg.cta;
			const label = ( state.cfg.i18n && state.cfg.i18n.buy ) || 'Buy';

			const cta = el( 'button', 'ocsp__product-cta' + ( plus ? ' ocsp__product-cta--plus' : '' ), {
				type: 'button',
				text: plus ? '+' : label,
				'aria-label': plus ? label : false,
				disabled: gone,
			} );

			cta.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				e.stopPropagation();
				quickAdd( product, cta );
			} );

			card.append( info, cta );

			card.addEventListener( 'click', () => {
				attribute( product );
				track( 'p', { l: slide().i } );
			} );

			return card;
		} )
	);

	ui.products.scrollLeft = 0;
	setTimeout( paintStripNav, 0 );

	// Pins mark a product's spot in the frame — image slides only. On video
	// the frame moves while a pin cannot, which reads as a mistake.
	const pinned = isImage()
		? list.filter( ( product ) => null !== product.x && null !== product.y )
		: [];

	ui.pins.replaceChildren(
		...pinned.map( ( product, index ) => {
			const pin = el( 'button', 'ocsp__pin', {
				type: 'button',
				text: String( index + 1 ),
				'aria-label': product.n,
				style: 'left:' + product.x * 100 + '%;top:' + product.y * 100 + '%',
			} );

			// A pin answers "which one is this?" — tapping it points at the
			// card that sells it, rather than yanking the shopper off the page.
			pin.addEventListener( 'click', ( e ) => {
				e.stopPropagation();
				const card = ui.products.querySelector( '[data-pid="' + product.i + '"]' );
				if ( card ) {
					card.scrollIntoView( { behavior: 'smooth', inline: 'center', block: 'nearest' } );
					card.classList.add( 'is-hot' );
					setTimeout( () => card.classList.remove( 'is-hot' ), 1400 );
				}
			} );

			return pin;
		} )
	);
}

/* ------------------------------------------------------------- quick add */

function api( path, init ) {
	return fetch( ( state.cfg.api || '' ).replace( /\/$/, '' ) + path, init ).then( ( response ) => {
		return response.json().then( ( body ) => {
			if ( ! response.ok ) {
				throw new Error( ( body && body.message ) || 'failed' );
			}
			return body;
		} );
	} );
}

let toastTimer = 0;

function showToast( message ) {
	if ( ! message ) {
		return;
	}

	ui.toast.textContent = message;
	ui.toast.hidden = false;
	clearTimeout( toastTimer );
	toastTimer = setTimeout( () => {
		ui.toast.hidden = true;
	}, 2800 );
}

function postCart( body, button ) {
	const i18n = state.cfg.i18n || {};
	const label = button.textContent;

	button.disabled = true;
	button.textContent = '…';

	let claim = '';
	try {
		claim = sessionStorage.getItem( 'ocs_attr' ) || '';
	} catch ( e ) {}

	return api( '/cart', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( Object.assign( { attr: claim }, body ) ),
	} ).then( ( result ) => {
		button.textContent = i18n.added || 'Added';
		button.classList.add( 'is-added' );
		button.disabled = false;

		// The response carries the same fragment payload WooCommerce's own
		// AJAX add produces, and it is applied the same way: each selector's
		// element replaced with its fresh markup. That is the channel the
		// theme's header count and cart drawer listen on, so they update
		// without a page load and without depending on wc-cart-fragments
		// being enqueued at all.
		if ( result && result.fragments ) {
			Object.keys( result.fragments ).forEach( ( selector ) => {
				document.querySelectorAll( selector ).forEach( ( node ) => {
					node.outerHTML = result.fragments[ selector ];
				} );
			} );

			// Seed Woo's fragment cache so its next refresh agrees with us.
			try {
				if ( window.sessionStorage && window.wc_cart_fragments_params ) {
					sessionStorage.setItem( window.wc_cart_fragments_params.fragment_name, JSON.stringify( result.fragments ) );
					if ( result.hash ) {
						sessionStorage.setItem( 'wc_cart_hash_' + ( window.wc_cart_fragments_params.cart_hash_key || '' ), result.hash );
					}
				}
			} catch ( e ) {}
		} else {
			// No fragment payload — nudge whatever count badge the page has.
			document.querySelectorAll( '.oc-cart-count, .cart-contents-count, .cart-count, [data-cart-count]' ).forEach( ( node ) => {
				node.textContent = result && result.count;
			} );
		}

		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
		}

		return true;
	} ).catch( ( error ) => {
		// WooCommerce's own words — "sold individually", "not enough stock" —
		// not a shrug.
		showToast( error && error.message );
		button.textContent = i18n.unavailable || '—';
		setTimeout( () => {
			button.textContent = label;
			button.disabled = false;
		}, 1500 );
		return false;
	} );
}

function quickAdd( product, button ) {
	attribute( product );
	track( 'p', { l: slide().i } );

	// The wide panel is most of a product page, so it opens for everything.
	// The narrow one only has something to say when there is a choice to
	// make — or when the answer is that there is nothing to buy.
	if ( product.v || 'full' === state.cfg.panel || 0 === product.s ) {
		openSheet( product );
		return;
	}

	postCart( { product: product.i }, button );
}

/* ------------------------------------------------------------- the sheet */

function openSheet( product ) {
	const i18n = state.cfg.i18n || {};

	state.sheetFor = product;

	// The wide panel prints the name in the body, under the photographs, so
	// the title bar would be saying it twice.
	ui.sheetTitle.textContent = 'full' === state.cfg.panel ? '' : product.n;
	ui.sheetBody.replaceChildren( el( 'p', 'ocsp__sheet-wait', { text: '…' } ) );
	// The next product is not the last one: start at the top, with no fade
	// left over from a panel that did have more to show.
	ui.sheetBody.scrollTop = 0;
	ui.sheet.classList.remove( 'ocsp__sheet--more', 'is-at-end' );
	ui.sheetPrice.textContent = product.p;
	ui.sheetAdd.textContent = i18n.add || 'Add to cart';
	ui.sheetAdd.disabled = true;

	ui.sheet.classList.toggle( 'ocsp__sheet--wide', 'full' === state.cfg.panel );
	ui.sheet.hidden = false;
	// Both paths are idempotent; rAF gives the transition a painted frame to
	// start from, the timer covers throttled tabs and battery-saver modes.
	requestAnimationFrame( () => ui.sheet.setAttribute( 'data-open', '1' ) );
	setTimeout( () => ui.sheet.setAttribute( 'data-open', '1' ), 60 );

	api( '/product/' + product.i ).then( ( data ) => {
		if ( state.sheetFor !== product ) {
			return;
		}
		buildSheet( product, data );
	} ).catch( () => {
		ui.sheetBody.replaceChildren( el( 'p', 'ocsp__sheet-wait', { text: i18n.unavailable || '—' } ) );
	} );
}

function buildSheet( product, data ) {
	const i18n = state.cfg.i18n || {};
	const chosen = {};
	const wide = 'full' === state.cfg.panel;
	let quantity = 1;

	// What the wide panel puts above the choices: the photographs, the name,
	// what people made of it, what it costs, and what it is. None of this is
	// in the page payload — it is fetched on a tap, by somebody who has
	// already decided to look.
	const head = [];
	// Anything that has to measure itself. Nothing in `head` is in the
	// document yet, so a strip asked for its width here answers zero — which
	// is how both arrows came to be hidden at once.
	const mounted = [];

	if ( wide ) {
		if ( data.images && data.images.length ) {
			const shots = el( 'div', 'ocsp__shots' );
			const strip = el( 'div', 'ocsp__shot-strip' );

			data.images.forEach( ( src, i ) => {
				strip.append( el( 'img', 'ocsp__shot', { src, alt: '', loading: i ? 'lazy' : 'eager' } ) );
			} );

			shots.append( strip );

			if ( data.images.length > 1 ) {
				const dots = el( 'div', 'ocsp__shot-dots' );

				data.images.forEach( ( src, i ) => {
					dots.append( el( 'span', 'ocsp__shot-dot' + ( i ? '' : ' is-on' ) ) );
				} );

				// A mouse has no swipe. Named and placed physically, because
				// scrollLeft is a physical offset and is negative in RTL —
				// the same rule the product row underneath already follows.
				const back = el( 'button', 'ocsp__strip-nav ocsp__strip-nav--l ocsp__shot-nav', {
					type: 'button',
					'aria-label': i18n.prev || 'Previous',
				} );
				const fwd = el( 'button', 'ocsp__strip-nav ocsp__strip-nav--r ocsp__shot-nav', {
					type: 'button',
					'aria-label': i18n.next || 'Next',
				} );

				// Entirely physical, like the product row below it. The left
				// button scrolls left and the right button scrolls right; in
				// an RTL panel the second photograph *is* to the left, so
				// that is also the one that moves forward. Any cleverness
				// about direction here would be cleverness applied twice,
				// because the CSS has already placed them.
				const span = () => strip.scrollWidth - strip.clientWidth;
				const edges = () => ( RTL() ? { min: -span(), max: 0 } : { min: 0, max: span() } );

				const paint = () => {
					const at = Math.round( Math.abs( strip.scrollLeft ) / Math.max( 1, strip.clientWidth ) );
					const range = edges();

					Array.prototype.forEach.call( dots.children, ( dot, i ) => {
						dot.classList.toggle( 'is-on', i === at );
					} );

					back.hidden = strip.scrollLeft <= range.min + 2;
					fwd.hidden = strip.scrollLeft >= range.max - 2;
				};

				// Snap and a smooth scroll fight: asked to glide somewhere it
				// must also snap to, a browser frequently gives up and jumps —
				// which is what that looked like. So the snap is lifted for
				// the length of the glide and put back once the strip has
				// stopped. A glide is exactly one photograph wide from a
				// snapped position, so it lands on a snap point either way and
				// nothing corrects itself when the snap returns.
				let settle = 0;

				const slideBy = ( physical ) => {
					const range = edges();
					const to = Math.max( range.min, Math.min( range.max, strip.scrollLeft + physical * strip.clientWidth ) );

					if ( CALM() ) {
						strip.scrollLeft = to;
						return;
					}

					clearTimeout( settle );
					strip.style.scrollSnapType = 'none';
					strip.scrollTo( { left: to, behavior: 'smooth' } );

					settle = setTimeout( () => {
						strip.style.scrollSnapType = '';
					}, 700 );
				};

				back.addEventListener( 'click', () => slideBy( -1 ) );
				fwd.addEventListener( 'click', () => slideBy( 1 ) );

				strip.addEventListener( 'scroll', paint, { passive: true } );

				shots.append( dots, back, fwd );
				mounted.push( paint );
			}

			head.push( shots );
		}

		head.push( el( 'h2', 'ocsp__sheet-name', { text: data.name || product.n } ) );

		if ( data.reviews > 0 ) {
			const stars = el( 'span', 'ocsp__stars' );
			const base = el( 'span', 'ocsp__stars-base', { text: '★★★★★', 'aria-hidden': 'true' } );

			base.append( el( 'span', 'ocsp__stars-fill', {
				text: '★★★★★',
				style: 'width:' + Math.min( 100, ( data.rating / 5 ) * 100 ) + '%',
			} ) );

			stars.append( base, el( 'span', 'ocsp__stars-count', { text: '(' + data.reviews + ')' } ) );
			head.push( stars );
		}

		if ( data.excerpt ) {
			const words = el( 'p', 'ocsp__sheet-text', { text: data.excerpt } );
			const more = el( 'button', 'ocsp__sheet-more', {
				type: 'button',
				text: i18n.readMore || '',
			} );

			more.addEventListener( 'click', () => {
				words.classList.add( 'is-open' );
				more.remove();
			} );

			head.push( words, more );
		}
	}

	const resolve = () => {
		// Nothing to choose and nothing in stock: say so where the price goes
		// and leave the button off. This is the whole of what a sold-out
		// product's panel has to do.
		if ( ! data.in_stock ) {
			ui.sheetPrice.textContent = i18n.soldOut || '—';
			ui.sheetPrice.classList.add( 'is-gone' );
			ui.sheetAdd.disabled = true;
			return;
		}

		ui.sheetPrice.classList.remove( 'is-gone' );

		// A product with no variations to pick has already been resolved: the
		// wide panel opens for those too, so the button has to work.
		if ( ! data.attributes.length ) {
			ui.sheetAdd.disabled = false;
			ui.sheetAdd.onclick = () => {
				postCart( { product: product.i, quantity }, ui.sheetAdd ).then( ( ok ) => {
					if ( ok ) {
						setTimeout( closeSheet, 900 );
					}
				} );
			};

			return;
		}

		const complete = data.attributes.every( ( attribute ) => chosen[ attribute.name ] );
		const match = complete
			? data.variations.find( ( variation ) => data.attributes.every( ( attribute ) => {
				const want = variation.attrs[ attribute.name ];
				// An empty variation attribute means "any".
				return '' === want || want === chosen[ attribute.name ];
			} ) )
			: null;

		if ( match && match.in_stock ) {
			ui.sheetPrice.textContent = match.price;
			ui.sheetAdd.disabled = false;
			ui.sheetAdd.onclick = () => {
				postCart( { product: product.i, variation: match.id, attributes: chosen, quantity }, ui.sheetAdd ).then( ( ok ) => {
					if ( ok ) {
						setTimeout( closeSheet, 900 );
					}
				} );
			};
		} else {
			// A chosen combination that is out of stock is out of stock —
			// not "unavailable", which reads like a fault at our end.
			ui.sheetPrice.textContent = complete ? ( i18n.soldOut || '—' ) : product.p;
			ui.sheetPrice.classList.toggle( 'is-gone', complete );
			ui.sheetAdd.disabled = true;
		}
	};

	/**
	 * After a choice is made, show what there is left to choose.
	 *
	 * A panel with three attributes answers the first one and then looks
	 * finished, because the second is below the fold. So the panel comes up
	 * far enough to put the next group in sight — and no further, and not at
	 * all when it is already there. Nothing moves that did not need to.
	 *
	 * @param {number} index Which group was just answered.
	 */
	const revealAfter = ( index ) => {
		const body = ui.sheetBody;
		// After the last group the thing worth seeing is the quantity, since
		// the button itself never leaves the bottom of the panel.
		const next = choices[ index + 1 ] || tail[ 0 ];

		if ( ! next || body.scrollHeight - body.clientHeight <= 12 ) {
			return;
		}

		// Measured against the visible edge rather than the content, so this
		// is the distance the panel is actually short by — plus a little, so
		// the next group does not sit flush against the fade.
		const below = next.getBoundingClientRect().bottom + 14 - body.getBoundingClientRect().bottom;

		if ( below <= 0 ) {
			return;
		}

		body.scrollTo( { top: body.scrollTop + below, behavior: CALM() ? 'auto' : 'smooth' } );
	};

	const choices = data.attributes.map( ( attribute, index ) => {
			const wrap = el( 'div', 'ocsp__opt-group' );
			const label = el( 'span', 'ocsp__opt-label', { text: attribute.label } );
			const picked = el( 'b', 'ocsp__opt-picked' );
			label.append( picked );
			wrap.append( label );

			// One option is not a choice. Pre-select it, so a product with a
			// single colour is one tap from the cart instead of a quiz.
			const lone = 1 === attribute.options.length;
			const style = attribute.style || 'button';

			const pick = ( option ) => {
				chosen[ attribute.name ] = option.slug;
				picked.textContent = ': ' + option.label;
			};

			if ( 'select' === style && ! lone ) {
				// The theme shows a dropdown here, so the sheet does too.
				const select = el( 'select', 'ocsp__opt-select' );
				select.append( el( 'option', '', { value: '', text: '—' } ) );
				attribute.options.forEach( ( option ) => {
					select.append( el( 'option', '', { value: option.slug, text: option.label } ) );
				} );

				select.addEventListener( 'change', () => {
					const option = attribute.options.find( ( o ) => o.slug === select.value );
					if ( option ) {
						pick( option );
					} else {
						delete chosen[ attribute.name ];
						picked.textContent = '';
					}
					resolve();

					if ( option ) {
						revealAfter( index );
					}
				} );

				wrap.append( select );
				return wrap;
			}

			const row = el( 'div', 'ocsp__opts' );
			row.append(
				...attribute.options.map( ( option ) => {
					let pill;

					if ( 'swatch' === style && ( option.color || option.image ) ) {
						// The theme's own swatch: the term's colour or image.
						pill = el( 'button', 'ocsp__opt ocsp__opt--swatch', {
							type: 'button',
							title: option.label,
							'aria-label': option.label,
							'aria-pressed': lone ? 'true' : 'false',
							style: option.image
								? 'background-image:url(' + option.image + ')'
								: 'background-color:' + option.color,
						} );
					} else {
						pill = el( 'button', 'ocsp__opt', { type: 'button', text: option.label, 'aria-pressed': lone ? 'true' : 'false' } );
					}

					if ( lone ) {
						pick( option );
					}

					pill.addEventListener( 'click', () => {
						pick( option );
						Array.from( row.children ).forEach( ( sibling ) => sibling.setAttribute( 'aria-pressed', 'false' ) );
						pill.setAttribute( 'aria-pressed', 'true' );
						resolve();
						revealAfter( index );
					} );

					return pill;
				} )
			);

			wrap.append( row );
			return wrap;
		} );

	const tail = [];

	if ( wide && data.in_stock ) {
		const count = el( 'b', 'ocsp__qty-n', { text: '1' } );

		const step = ( by ) => {
			const ceiling = data.max > 0 ? data.max : 999;

			quantity = Math.max( 1, Math.min( ceiling, quantity + by ) );
			count.textContent = String( quantity );
		};

		const stepper = el( 'div', 'ocsp__qty' );

		const less = el( 'button', 'ocsp__qty-b', { type: 'button', text: '−', 'aria-label': i18n.quantity || '' } );
		const more = el( 'button', 'ocsp__qty-b', { type: 'button', text: '+', 'aria-label': i18n.quantity || '' } );

		less.addEventListener( 'click', () => step( -1 ) );
		more.addEventListener( 'click', () => step( 1 ) );

		stepper.append( less, count, more );

		// A way through to the whole product page, for the questions a panel
		// cannot answer.
		const out = el( 'a', 'ocsp__sheet-out', {
			href: data.url || product.u,
			'aria-label': i18n.openProduct || '',
			text: '↗',
		} );

		tail.push( el( 'div', 'ocsp__qty-row' ) );
		tail[ 0 ].append( stepper, out );
	}

	ui.sheetBody.replaceChildren( ...head, ...choices, ...tail );

	mounted.forEach( ( run ) => run() );

	resolve();
	paintMore();
}

/**
 * Say that there is more below, but only when there is.
 *
 * George's question was whether the panel could avoid scrolling altogether.
 * For most products it now does — the photograph was the thing pushing
 * everything down. For the rest there is a fade at the edge of the reading
 * area, and nothing else: the panel opened with a small scroll down and back,
 * and a panel that moves by itself on opening reads as a fault rather than as
 * an invitation.
 */
function paintMore() {
	const body = ui.sheetBody;

	// Measured, not guessed. Reading scrollHeight forces the layout we need,
	// and this runs again whenever a picture inside finishes arriving, so a
	// panel that grows late still tells the truth.
	const over = body.scrollHeight - body.clientHeight;

	ui.sheet.classList.toggle( 'ocsp__sheet--more', over > 12 );
	ui.sheet.classList.toggle( 'is-at-end', over - body.scrollTop < 8 );
}

function closeSheet( instant ) {
	if ( ! ui || ui.sheet.hidden ) {
		return;
	}

	state.sheetFor = null;
	ui.sheet.removeAttribute( 'data-open' );

	const hide = () => {
		ui.sheet.hidden = true;
	};

	if ( instant ) {
		hide();
		return;
	}

	setTimeout( hide, 220 );

	// The story kept playing under the sheet; if it reached its end while the
	// shopper was choosing, it moves on now.
	if ( isImage() ) {
		const current = slide();
		if ( clockNow() >= ( ( current && current.d ) || 5 ) ) {
			go( 1 );
		}
	} else if ( ui.video.ended ) {
		go( 1 );
	}
}

/**
 * Remember which story sent a shopper to a product.
 *
 * sessionStorage rather than a cookie, deliberately: a cookie would vary the
 * request and break full-page caching for every page on the shop, to record
 * something only the checkout ever reads.
 *
 * @param {Object} product Product.
 */
function attribute( product ) {
	try {
		sessionStorage.setItem( 'ocs_attr', JSON.stringify( {
			story: story().i,
			slide: slide().i,
			product: product.i,
			// So a sale can be credited to the gallery it came from, not only
			// to the video.
			bar: state.bar,
			ts: Date.now(),
		} ) );
	} catch ( e ) {}
}

/* ------------------------------------------------------------- transport */

let raf = 0;

/**
 * Move the segment for the slide being watched.
 *
 * @return {void}
 */
function paintProgress() {
	if ( ! state ) {
		return;
	}

	const bar = ui.bars.children[ state.qi ];
	const current = slide();
	const image = isImage();
	const total = image ? ( current.d || 5 ) : ( ui.video.duration || ( current && current.d ) || 0 );
	const at = image ? clockNow() : ui.video.currentTime;

	if ( bar && total ) {
		bar.firstChild.style.width = Math.min( 100, ( at / total ) * 100 ) + '%';
	}

	if ( image && total && clockNow() >= total ) {
		if ( ! ui.sheet.hidden || state.browsing ) {
			return;
		}
		if ( state.qi === story().s.length - 1 ) {
			track( 'd' );
		}
		go( 1 );
	}
}

/**
 * Smooth updates while the page is visible.
 *
 * requestAnimationFrame stops in a background tab while the video keeps
 * playing, so this cannot be the only thing driving the bar — `timeupdate`
 * is bound alongside it and keeps the segment honest at about four frames a
 * second whatever the page is doing.
 */
function tick() {
	paintProgress();
	raf = requestAnimationFrame( tick );
}

function play() {
	const current = slide();

	if ( ! current ) {
		return;
	}

	ui.title.textContent = story().t || '';
	closeSheet( true );

	if ( ! turning && ui.stage.classList.contains( 'is-turning' ) ) {
		ui.stage.classList.remove( 'is-turning' );
		ui.stage.style.transition = '';
		ui.stage.style.transform = '';
		ui.stage.style.opacity = '';
	}

	const image = 'i' === current.ty;

	// Portrait media fills the whole stage edge to edge; landscape keeps its
	// shape over a blurred fill of itself instead of black bars.
	const portrait = ! current.w || ! current.h || current.h >= current.w;
	const media = image ? ui.image : ui.video;
	ui.image.classList.toggle( 'is-cover', portrait );
	ui.video.classList.toggle( 'is-cover', portrait );

	ui.blur.hidden = portrait || ! current.p;
	if ( ! portrait && current.p ) {
		ui.blur.src = current.p;
	}

	ui.image.hidden = ! image;
	ui.video.hidden = image;
	state.clock = { start: performance.now(), elapsed: 0, running: image };

	if ( image ) {
		ui.video.pause();
		ui.video.removeAttribute( 'src' );
		ui.video.load();
		ui.unmute.hidden = true;
		ui.image.src = current.u || current.p;
	} else {
		ui.unmute.hidden = false;
		paintMute();
		ui.image.removeAttribute( 'src' );
		ui.video.poster = current.p || '';
		ui.video.src = current.u;

		// The tap that opened the player is the user gesture iOS requires, so
		// sound is allowed. If the browser disagrees anyway, fall back to muted
		// and offer a control rather than failing to play at all.
		ui.video.muted = state.muted;

		const started = ui.video.play();

		if ( started && started.catch ) {
			started.catch( () => {
				state.muted = true;
				ui.video.muted = true;
				paintMute();
				ui.video.play().catch( () => {} );
			} );
		}
	}

	paintBars();
	paintProducts();
	paintReactions();

	ui.railUp.disabled = 0 === state.si;
	ui.railDown.disabled = state.si >= state.stories.length - 1;

	Array.prototype.forEach.call( ui.thumbs.children, ( node, i ) => {
		node.classList.toggle( 'is-on', i === state.si );
	} );

	cancelAnimationFrame( raf );
	raf = requestAnimationFrame( tick );

	const ahead = story().s[ state.qi + 1 ];
	ui.ahead.src = ahead && 'i' !== ahead.ty ? ahead.u : '';
}

/**
 * Move a whole gallery, with the turn.
 *
 * @param {number} direction +1 forward, -1 back.
 */
function jump( direction ) {
	const at = state.si + direction;

	if ( at < 0 || at >= state.stories.length ) {
		return;
	}

	cube( direction, () => {
		state.si = at;
		state.qi = 0;
		state.onSeen( story().i );
		track( 'o' );
		play();
	} );
}

function go( direction ) {
	const current = story();
	const at = state.qi + direction;

	if ( at >= 0 && at < current.s.length ) {
		state.qi = at;
		rewound = false;
		play();
		return;
	}

	// Back from the first slide starts this story again. Leaving for the
	// story before it on the first press is the wrong reading of "back" when
	// somebody has just watched something and wants to see it again — and it
	// is a long way to come back from. The second press in a row is the one
	// that leaves.
	if ( direction < 0 && ! rewound ) {
		rewound = true;
		state.qi = 0;
		play();
		return;
	}

	const nextStory = state.si + direction;

	if ( nextStory < 0 ) {
		rewound = false;
		state.qi = 0;
		play();
		return;
	}

	if ( nextStory >= state.stories.length || ! state.cfg.next ) {
		close();
		return;
	}

	cube( direction, () => {
		state.si = nextStory;
		rewound = false;
		state.qi = direction > 0 ? 0 : state.stories[ nextStory ].s.length - 1;
		state.onSeen( story().i );
		track( 'o' );
		play();
	} );
}

/* -------------------------------------------------------------- the cube */

const CALM = () => window.matchMedia && matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

let turning = false;
let turnGuard = 0;

/**
 * Turn to the next gallery like a face of a cube.
 *
 * Half a rotation out, swap what the stage holds, half a rotation in from the
 * opposite side. Two halves of a turn read as one solid object rotating, and
 * it costs one element and no second copy of the video.
 *
 * @param {number}   direction  +1 forward, -1 back.
 * @param {Function} swap       Puts the new gallery on the stage.
 */
function cube( direction, swap ) {
	if ( CALM() || turning ) {
		swap();
		return;
	}

	turning = true;

	const face = ui.stage;
	const away = direction > 0 ? -88 : 88;

	/**
	 * Put the face straight, whatever state it is in.
	 *
	 * The turn is a chain of timers, and timers are throttled hard in a
	 * background tab — switch away mid-rotation and the chain stalls with the
	 * video standing on its edge. This runs from a guard well past the honest
	 * duration, and again at the start of every slide, so a stalled turn
	 * cannot survive into what the shopper sees next.
	 */
	const straighten = () => {
		face.classList.remove( 'is-turning' );
		face.style.transition = '';
		face.style.transform = '';
		face.style.opacity = '';
		turning = false;
	};

	clearTimeout( turnGuard );
	turnGuard = setTimeout( straighten, 1400 );

	face.classList.add( 'is-turning' );
	face.style.transform = 'perspective(1200px) rotateY(0deg)';

	setTimeout( () => {
		face.style.transition = 'transform .2s ease-in, opacity .2s ease-in';
		face.style.opacity = '.35';
		face.style.transform = 'perspective(1200px) rotateY(' + away + 'deg)';

		setTimeout( () => {
			swap();

			face.style.transition = 'none';
			face.style.transform = 'perspective(1200px) rotateY(' + -away + 'deg)';

			setTimeout( () => {
				face.style.transition = 'transform .22s ease-out, opacity .22s ease-out';
				face.style.opacity = '1';
				face.style.transform = 'perspective(1200px) rotateY(0deg)';

				setTimeout( straighten, 240 );
			}, 20 );
		}, 210 );
	}, 20 );
}

/* ------------------------------------------------------------- the spark */

const MINE = 'ocs_reacted';
const HINTED = 'ocs_hinted';

function mine() {
	try {
		return JSON.parse( localStorage.getItem( MINE ) || '{}' );
	} catch ( e ) {
		return {};
	}
}

/**
 * A reaction belongs to the slide, not the gallery.
 *
 * A gallery can hold five clips; saying "that one" about the third has to
 * mean the third.
 *
 * @return {string}
 */
function slideKey() {
	const current = slide();

	return story().i + ':' + ( current ? current.i : '' );
}

function tally( kind ) {
	const current = slide();
	const base = current ? ( 'spark' === kind ? current.sp : current.lk ) || 0 : 0;
	const own = mine()[ slideKey() ] || {};

	return base + ( own[ kind ] ? 1 : 0 );
}

function paintReactions() {
	const own = mine()[ slideKey() ] || {};

	[ [ ui.like, 'like' ], [ ui.spark, 'spark' ] ].forEach( ( pair ) => {
		const count = tally( pair[ 1 ] );

		pair[ 0 ].classList.toggle( 'is-on', !! own[ pair[ 1 ] ] );
		pair[ 0 ].querySelector( '.ocsp__react-count' ).textContent = count > 0 ? count : '';
	} );
}

/**
 * Throw a handful of sparks from a point on the stage.
 *
 * @param {number} x    Fraction across the stage.
 * @param {number} y    Fraction down the stage.
 * @param {string} kind Which reaction threw them.
 */
function burst( x, y, kind ) {
	if ( CALM() ) {
		return;
	}

	const field = el( 'div', 'ocsp__burst ocsp__burst--' + kind );
	field.style.left = x * 100 + '%';
	field.style.top = y * 100 + '%';
	field.append( el( 'span', 'ocsp__flash' ) );

	const bits = 'spark' === kind ? 20 : 10;

	for ( let i = 0; i < bits; i++ ) {
		const bit = el( 'span', 'ocsp__bit' );
		const angle = ( i / bits ) * Math.PI * 2 + Math.random() * 0.5;
		const reach = 70 + Math.random() * 90;

		bit.style.setProperty( '--dx', Math.cos( angle ) * reach + 'px' );
		bit.style.setProperty( '--dy', Math.sin( angle ) * reach + 'px' );
		bit.style.setProperty( '--size', ( 'spark' === kind ? 8 + Math.random() * 7 : 7 ) + 'px' );
		bit.style.animationDelay = Math.random() * 70 + 'ms';

		field.append( bit );
	}

	ui.stage.append( field );
	setTimeout( () => field.remove(), 1100 );
}

/**
 * React to this slide. Once per person per slide either way — a second tap
 * still bursts, because taking the mark away would be a strange punishment,
 * but it is not counted twice.
 *
 * @param {string} kind 'spark' or 'like'.
 * @param {number} x    Fraction across the stage.
 * @param {number} y    Fraction down the stage.
 */
function react( kind, x, y ) {
	burst( x, y, kind );

	const all = mine();
	const key = slideKey();

	all[ key ] = all[ key ] || {};

	if ( ! all[ key ][ kind ] ) {
		all[ key ][ kind ] = 1;

		try {
			localStorage.setItem( MINE, JSON.stringify( all ) );
		} catch ( e ) {}

		track( 'spark' === kind ? 'k' : 'h', { l: slide().i } );
	}

	const button = 'spark' === kind ? ui.spark : ui.like;
	button.classList.add( 'is-hit' );
	setTimeout( () => button.classList.remove( 'is-hit' ), 500 );

	hideHint();
	paintReactions();
}

function centreOf( node ) {
	const box = ui.stage.getBoundingClientRect();
	const mark = node.getBoundingClientRect();

	return {
		x: ( mark.left + mark.width / 2 - box.left ) / box.width,
		y: ( mark.top + mark.height / 2 - box.top ) / box.height,
	};
}

function hideHint() {
	ui.hint.hidden = true;

	try {
		localStorage.setItem( HINTED, '1' );
	} catch ( e ) {}
}

function maybeHint() {
	let seen = '1';

	try {
		seen = localStorage.getItem( HINTED );
	} catch ( e ) {}

	if ( seen || ! ui.hint.textContent ) {
		return;
	}

	ui.hint.hidden = false;
	setTimeout( hideHint, 5000 );
}

/* ------------------------------------------------------------- the strip */

/**
 * How far the strip can travel either way.
 *
 * scrollLeft is physical everywhere, but its range flips in RTL: browsers
 * count from 0 at the right edge down to a negative floor. Both ends are
 * derived here so the rest of the code can stay in physical pixels.
 *
 * @return {{min: number, max: number}}
 */
function stripRange() {
	const span = ui.products.scrollWidth - ui.products.clientWidth;

	return RTL() ? { min: -span, max: 0 } : { min: 0, max: span };
}

function paintStripNav() {
	const range = stripRange();
	const at = ui.products.scrollLeft;
	const room = range.max - range.min > 4;

	ui.stripBack.hidden = ! room || at <= range.min + 2;
	ui.stripFwd.hidden = ! room || at >= range.max - 2;
}

function nudgeStrip( physical ) {
	const step = Math.max( 120, ui.products.clientWidth * 0.8 );
	const range = stripRange();

	ui.products.scrollTo( {
		left: Math.max( range.min, Math.min( range.max, ui.products.scrollLeft + physical * step ) ),
		behavior: 'smooth',
	} );
}

function bindStrip() {
	// Dragging is the primary way through the row: it needs no affordance, it
	// works with a mouse where there is no horizontal wheel, and on touch the
	// browser is already doing it — which is why a finger is left alone here.
	let holdingStrip = false;
	let fromX = 0;
	let fromScroll = 0;
	let dragged = false;

	ui.products.addEventListener( 'pointerdown', ( e ) => {
		if ( 'mouse' !== e.pointerType ) {
			return;
		}

		// Nothing is claimed here. Capturing the pointer on the row — and
		// cancelling the press — retargets the click that follows to the row
		// itself, so pressing a product card or Buy did nothing at all: the
		// click never reached them. The cards' own `draggable="false"` is
		// what stops the browser dragging them; this handler only needs to
		// remember where the press began.
		holdingStrip = true;
		dragged = false;
		fromX = e.clientX;
		fromScroll = ui.products.scrollLeft;
	} );

	ui.products.addEventListener( 'pointermove', ( e ) => {
		if ( ! holdingStrip ) {
			return;
		}

		const dx = e.clientX - fromX;

		if ( ! dragged && Math.abs( dx ) > 4 ) {
			// Now it is a drag rather than a press, so take the pointer —
			// late, once there is something to keep hold of. A hand that
			// strays off the row mid-drag would otherwise drop it.
			dragged = true;
			ui.products.classList.add( 'is-dragging' );

			try {
				ui.products.setPointerCapture( e.pointerId );
			} catch ( err ) {}
		}

		if ( dragged ) {
			ui.products.scrollLeft = fromScroll - dx;
		}
	} );

	const release = () => {
		holdingStrip = false;
		ui.products.classList.remove( 'is-dragging' );
	};

	ui.products.addEventListener( 'pointerup', release );
	ui.products.addEventListener( 'pointercancel', release );

	// A drag that ends on a card must not also open that product.
	ui.products.addEventListener( 'click', ( e ) => {
		if ( dragged ) {
			e.preventDefault();
			e.stopPropagation();
			dragged = false;
		}
	}, true );

	ui.products.addEventListener( 'scroll', paintStripNav, { passive: true } );

	// Someone whose pointer is in the product row is deciding whether to buy.
	// Moving the story on under them is the one thing that would lose the
	// sale, so the story simply waits.
	ui.products.addEventListener( 'pointerenter', ( e ) => {
		if ( 'touch' !== e.pointerType ) {
			state.browsing = true;
			pausePlayback();
		}
	} );

	ui.products.addEventListener( 'pointerleave', ( e ) => {
		if ( 'touch' !== e.pointerType ) {
			state.browsing = false;
			resumePlayback();
		}
	} );

	// A finger has no hover, so touching the row does the same and lets go a
	// few seconds after the last touch.
	let touchIdle = 0;

	ui.products.addEventListener( 'touchstart', () => {
		clearTimeout( touchIdle );
		state.browsing = true;
		pausePlayback();
	}, { passive: true } );

	ui.products.addEventListener( 'touchend', () => {
		clearTimeout( touchIdle );
		touchIdle = setTimeout( () => {
			state.browsing = false;
			resumePlayback();
		}, 3000 );
	}, { passive: true } );

	ui.stripBack.addEventListener( 'click', () => nudgeStrip( -1 ) );
	ui.stripFwd.addEventListener( 'click', () => nudgeStrip( 1 ) );
}

/* --------------------------------------------------------------- gestures */

let heldUntil = 0;

function bindGestures() {
	let x0 = 0;
	let y0 = 0;
	let held = null;
	let moved = false;
	// Whether this gesture is ours to interpret at all. Without it, a tap on
	// a product card ran the release handler with coordinates left over from
	// some earlier gesture, and a stale distance reads as a swipe — which is
	// why tapping a product, Buy, or a reaction closed the player instead.
	let armed = false;

	ui.stage.addEventListener( 'pointerdown', ( e ) => {
		// The strip, the sheet, the pins and the top controls are for touching:
		// scrolling products or tapping Buy must not pause the story under it.
		if ( e.target.closest( '.ocsp__products, .ocsp__sheet, .ocsp__top, .ocsp__unmute, .ocsp__pin, .ocsp__reactions' ) ) {
			held = null;
			moved = false;
			armed = false;
			return;
		}

		x0 = e.clientX;
		y0 = e.clientY;
		moved = false;
		armed = true;

		// Press and hold pauses, the way every story player does. 220ms is long
		// enough that an ordinary tap never triggers it.
		held = setTimeout( () => {
			held = 'active';
			pausePlayback();
		}, 220 );
	}, { passive: true } );

	ui.stage.addEventListener( 'pointermove', ( e ) => {
		if ( ! armed ) {
			return;
		}

		if ( Math.abs( e.clientX - x0 ) > 10 || Math.abs( e.clientY - y0 ) > 10 ) {
			moved = true;
		}
	}, { passive: true } );

	ui.stage.addEventListener( 'pointerup', ( e ) => {
		const wasHeld = 'active' === held;
		const mine = armed;

		clearTimeout( held );
		held = null;
		armed = false;

		if ( ! mine ) {
			// It began on something meant to be touched. That control has its
			// own handler and this one has nothing to say about it.
			return;
		}

		if ( wasHeld ) {
			// Releasing a long press resumes — it does not also turn the page,
			// which is what the click that follows would otherwise do.
			heldUntil = Date.now() + 400;
			resumePlayback();
			return;
		}

		// A tap is not a swipe. Only a pointer that actually travelled gets
		// measured; a stationary release belongs to whatever was tapped, and
		// to the zones' own click handlers.
		if ( ! moved ) {
			return;
		}

		const dx = e.clientX - x0;
		const dy = e.clientY - y0;

		if ( dy > 90 && Math.abs( dy ) > Math.abs( dx ) ) {
			close();
			return;
		}

		if ( Math.abs( dx ) > 60 ) {
			// A swipe means the next story, and "next" is leftwards only in a
			// left-to-right shop.
			go( ( dx < 0 ) === ! RTL() ? 1 : -1 );
		}
	} );

	// The zones are placed with logical properties: `--next` sits at the
	// inline end, which is the left in an RTL shop and the right in an LTR
	// one. Both are already "forward" for that reader, so inverting the
	// handler on top of that — as this did — cancelled the CSS out and made
	// forward mean back in Hebrew.
	// A click that ended a long press turns no page — releasing a hold means
	// "carry on", not "next".
	const stepper = ( direction ) => ( ) => {
		if ( Date.now() < heldUntil ) {
			return;
		}
		go( direction );
	};

	ui.prev.addEventListener( 'click', stepper( -1 ) );
	ui.next.addEventListener( 'click', stepper( 1 ) );

	ui.close.addEventListener( 'click', close );

	// The black around the video is a way out, the way any modal's backdrop is.
	ui.root.addEventListener( 'click', ( e ) => {
		if ( e.target === ui.root ) {
			close();
		}
	} );

	bindStrip();

	ui.railUp.addEventListener( 'click', () => jump( -1 ) );
	ui.railDown.addEventListener( 'click', () => jump( 1 ) );

	[ [ ui.like, 'like' ], [ ui.spark, 'spark' ] ].forEach( ( pair ) => {
		pair[ 0 ].addEventListener( 'click', ( e ) => {
			e.stopPropagation();
			const at = centreOf( pair[ 0 ] );
			react( pair[ 1 ], at.x, at.y );
		} );
	} );

	// The fade goes out at the bottom, which is the only place it would be
	// telling a lie.
	ui.sheetBody.addEventListener( 'scroll', paintMore, { passive: true } );

	// A picture arriving makes the panel taller after it was measured. `load`
	// does not bubble, so this listens on the way down instead — one listener
	// for every image the panel will ever hold.
	ui.sheetBody.addEventListener( 'load', paintMore, true );

	ui.sheetClose.addEventListener( 'click', () => closeSheet() );

	ui.unmute.addEventListener( 'click', () => {
		state.muted = ! state.muted;
		ui.video.muted = state.muted;
		paintMute();
	} );

	ui.video.addEventListener( 'timeupdate', paintProgress );
	ui.video.addEventListener( 'ended', () => {
		// Someone mid-choice in the sheet, or reading the products, keeps
		// their slide; the story resumes its course afterwards.
		if ( ! ui.sheet.hidden || state.browsing ) {
			return;
		}

		// The last slide finishing on its own is the only thing counted as
		// watching to the end — skipping ahead is interest, not completion.
		if ( state && state.qi === story().s.length - 1 ) {
			track( 'd' );
		}
		go( 1 );
	} );

	document.addEventListener( 'keydown', onKey );
}

function onKey( e ) {
	if ( ! state ) {
		return;
	}

	// The player is a modal, so Tab must cycle inside it. Without this, focus
	// walks off into the page underneath — invisible behind the overlay but
	// still operable, which is the worst of both.
	if ( 'Tab' === e.key ) {
		const focusable = Array.from( ui.root.querySelectorAll( 'button, a[href]' ) )
			.filter( ( node ) => ! node.hidden && null !== node.offsetParent );

		if ( ! focusable.length ) {
			return;
		}

		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		const active = document.activeElement;

		if ( ! ui.root.contains( active ) ) {
			e.preventDefault();
			first.focus();
		} else if ( e.shiftKey && active === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && active === last ) {
			e.preventDefault();
			first.focus();
		}
		return;
	}

	if ( 'Escape' === e.key ) {
		if ( ! ui.sheet.hidden ) {
			closeSheet();
			return;
		}
		close();
	} else if ( 'ArrowRight' === e.key ) {
		go( RTL() ? -1 : 1 );
	} else if ( 'ArrowLeft' === e.key ) {
		go( RTL() ? 1 : -1 );
	} else if ( ' ' === e.key ) {
		e.preventDefault();
		if ( isImage() ) {
			if ( state.clock.running ) {
				clockPause();
			} else {
				clockResume();
			}
		} else if ( ui.video.paused ) {
			ui.video.play().catch( () => {} );
		} else {
			ui.video.pause();
		}
	}
}

/* ------------------------------------------------------------ open, close */

/**
 * Open the player.
 *
 * @param {Array}  stories Payload from the bar.
 * @param {number} index   Story to start on.
 * @param {Object} ctx     { cfg, onSeen }
 */
export function open( stories, index, ctx ) {
	const usable = ( stories || [] ).filter( ( item ) => item && item.s && item.s.length );

	if ( ! usable.length ) {
		return;
	}

	if ( ! ui ) {
		ui = build( ctx.cfg );
		document.body.appendChild( ui.root );
		bindGestures();
	}

	state = {
		stories: usable,
		si: Math.max( 0, Math.min( index || 0, usable.length - 1 ) ),
		qi: 0,
		muted: false,
		browsing: false,
		cfg: ctx.cfg || {},
		surface: ctx.surface || '',
		bar: ctx.bar || '',
		onSeen: ctx.onSeen || function () {},
		returnTo: document.activeElement,
	};

	if ( 'thumbs' === ( state.cfg.nav || 'arrows' ) ) {
		ui.thumbs.textContent = '';

		usable.forEach( ( item, i ) => {
			const first = item.s[ 0 ] || {};
			const thumb = el( 'button', 'ocsp__thumb', { type: 'button', 'aria-label': item.t || '' } );

			if ( first.p ) {
				thumb.append( el( 'img', '', { src: first.p, alt: '', loading: 'lazy', decoding: 'async' } ) );
			}

			thumb.addEventListener( 'click', () => {
				if ( i !== state.si ) {
					jump( i - state.si );
				}
			} );

			ui.thumbs.append( thumb );
		} );
	}

	document.documentElement.setAttribute( 'data-ocsp-open', '1' );
	ui.root.setAttribute( 'data-open', '1' );
	ui.close.focus( { preventScroll: true } );

	rewound = false;
	state.onSeen( story().i );
	track( 'o' );
	play();
	maybeHint();
}

/**
 * Close and put the page back exactly as it was.
 */
export function close() {
	if ( ! state ) {
		return;
	}

	cancelAnimationFrame( raf );

	ui.video.pause();
	// Emptying the source stops the download too. Pausing alone leaves the rest
	// of the file arriving on someone's mobile data after they have left.
	ui.video.removeAttribute( 'src' );
	ui.video.load();
	ui.ahead.removeAttribute( 'src' );

	ui.root.removeAttribute( 'data-open' );
	document.documentElement.removeAttribute( 'data-ocsp-open' );

	const returnTo = state.returnTo;
	state = null;

	if ( returnTo && returnTo.focus ) {
		returnTo.focus( { preventScroll: true } );
	}
}
