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
		queue.push( Object.assign( { t: type, s: story().i, f: state.surface }, extra || {} ) );
	}
}

let ui = null;
let state = null;

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

			info.append( el( 'span', 'ocsp__product-price', { text: product.p } ) );

			const cta = el( 'button', 'ocsp__product-cta', {
				type: 'button',
				text: ( state.cfg.i18n && state.cfg.i18n.buy ) || 'Buy',
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

	if ( product.v ) {
		openSheet( product );
		return;
	}

	postCart( { product: product.i }, button );
}

/* ------------------------------------------------------------- the sheet */

function openSheet( product ) {
	const i18n = state.cfg.i18n || {};

	state.sheetFor = product;

	ui.sheetTitle.textContent = product.n;
	ui.sheetBody.replaceChildren( el( 'p', 'ocsp__sheet-wait', { text: '…' } ) );
	ui.sheetPrice.textContent = product.p;
	ui.sheetAdd.textContent = i18n.add || 'Add to cart';
	ui.sheetAdd.disabled = true;

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

	const resolve = () => {
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
				postCart( { product: product.i, variation: match.id, attributes: chosen }, ui.sheetAdd ).then( ( ok ) => {
					if ( ok ) {
						setTimeout( closeSheet, 900 );
					}
				} );
			};
		} else {
			ui.sheetPrice.textContent = complete ? ( i18n.unavailable || '—' ) : product.p;
			ui.sheetAdd.disabled = true;
		}
	};

	ui.sheetBody.replaceChildren(
		...data.attributes.map( ( attribute ) => {
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
					} );

					return pill;
				} )
			);

			wrap.append( row );
			return wrap;
		} )
	);

	resolve();
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
		play();
		return;
	}

	const nextStory = state.si + direction;

	if ( nextStory < 0 ) {
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

		// The cards are links wrapping images, and both of those start a
		// native drag of their own the moment the mouse moves — which cancels
		// our pointer stream and is why dragging did nothing at all.
		e.preventDefault();

		// And once the drag is ours, keep it: without capture, a hand that
		// strays off the row mid-drag drops it.
		try {
			ui.products.setPointerCapture( e.pointerId );
		} catch ( err ) {}

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

		if ( Math.abs( dx ) > 4 ) {
			dragged = true;
			ui.products.classList.add( 'is-dragging' );
		}

		ui.products.scrollLeft = fromScroll - dx;
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

	ui.stage.addEventListener( 'pointerdown', ( e ) => {
		// The strip, the sheet, the pins and the top controls are for touching:
		// scrolling products or tapping Buy must not pause the story under it.
		if ( e.target.closest( '.ocsp__products, .ocsp__sheet, .ocsp__top, .ocsp__unmute, .ocsp__pin, .ocsp__reactions' ) ) {
			held = null;
			moved = false;
			return;
		}

		x0 = e.clientX;
		y0 = e.clientY;
		moved = false;

		// Press and hold pauses, the way every story player does. 220ms is long
		// enough that an ordinary tap never triggers it.
		held = setTimeout( () => {
			held = 'active';
			pausePlayback();
		}, 220 );
	}, { passive: true } );

	ui.stage.addEventListener( 'pointermove', ( e ) => {
		if ( Math.abs( e.clientX - x0 ) > 10 || Math.abs( e.clientY - y0 ) > 10 ) {
			moved = true;
		}
	}, { passive: true } );

	ui.stage.addEventListener( 'pointerup', ( e ) => {
		const wasHeld = 'active' === held;

		clearTimeout( held );
		held = null;

		if ( wasHeld ) {
			// Releasing a long press resumes — it does not also turn the page,
			// which is what the click that follows would otherwise do.
			heldUntil = Date.now() + 400;
			resumePlayback();
			return;
		}

		// A tap used to be able to spark: two in quick succession, anywhere.
		// It sparked on single taps instead — the zones sit inside the stage,
		// so one tap ran both handlers and the second read the first's
		// timestamp as a double tap. It also made stepping through a gallery
		// spark constantly, which is worse than not having the gesture: a
		// reaction that fires by accident means nothing. The spark is the
		// spark button now, and only that.
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
