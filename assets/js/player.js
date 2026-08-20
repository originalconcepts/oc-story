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
	const stage = el( 'div', 'ocsp__stage' );

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
	const unmute = el( 'button', 'ocsp__btn ocsp__unmute', { type: 'button', 'aria-label': 'Sound', text: '♪' } );
	unmute.hidden = true;

	const top = el( 'div', 'ocsp__top' );
	top.append( title, close );

	const prev = el( 'button', 'ocsp__zone ocsp__zone--prev', { type: 'button', 'aria-label': i18n.prev || 'Previous' } );
	const next = el( 'button', 'ocsp__zone ocsp__zone--next', { type: 'button', 'aria-label': i18n.next || 'Next' } );

	const products = el( 'div', 'ocsp__products' );
	const pins = el( 'div', 'ocsp__pins' );

	// Metadata only, and only ever for the slide immediately after this one.
	const ahead = el( 'video', '', { preload: 'metadata' } );
	ahead.style.display = 'none';

	// The variations sheet, hidden until a Buy needs choices.
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

	stage.append( image, video, pins, bars, top, unmute, prev, next, products, ahead, sheet );
	root.append( stage );

	return { root, stage, image, video, ahead, bars, title, close, unmute, prev, next, products, pins, sheet, sheetTitle, sheetClose, sheetBody, sheetPrice, sheetAdd };
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
			const card = el( 'a', 'ocsp__product', { href: product.u, 'data-pid': product.i } );

			if ( product.t ) {
				card.append( el( 'img', 'ocsp__product-thumb', { src: product.t, alt: '', loading: 'lazy' } ) );
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
	} ).then( () => {
		button.textContent = i18n.added || 'Added';
		button.classList.add( 'is-added' );
		button.disabled = false;
		return true;
	} ).catch( () => {
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
	pausePlayback();

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
			wrap.append( el( 'span', 'ocsp__opt-label', { text: attribute.label } ) );

			const row = el( 'div', 'ocsp__opts' );
			row.append(
				...attribute.options.map( ( option ) => {
					const pill = el( 'button', 'ocsp__opt', { type: 'button', text: option.label, 'aria-pressed': 'false' } );

					pill.addEventListener( 'click', () => {
						chosen[ attribute.name ] = option.slug;
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
	} else {
		setTimeout( hide, 220 );
		resumePlayback();
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

	const image = 'i' === current.ty;

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
				ui.unmute.hidden = false;
				ui.video.play().catch( () => {} );
			} );
		}
	}

	paintBars();
	paintProducts();

	cancelAnimationFrame( raf );
	raf = requestAnimationFrame( tick );

	const ahead = story().s[ state.qi + 1 ];
	ui.ahead.src = ahead && 'i' !== ahead.ty ? ahead.u : '';
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

	state.si = nextStory;
	state.qi = direction > 0 ? 0 : state.stories[ nextStory ].s.length - 1;
	state.onSeen( story().i );
	track( 'o' );
	play();
}

/* --------------------------------------------------------------- gestures */

function bindGestures() {
	let x0 = 0;
	let y0 = 0;
	let held = null;
	let moved = false;

	ui.stage.addEventListener( 'pointerdown', ( e ) => {
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
			resumePlayback();
			return;
		}

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

	ui.prev.addEventListener( 'click', () => go( RTL() ? 1 : -1 ) );
	ui.next.addEventListener( 'click', () => go( RTL() ? -1 : 1 ) );
	ui.close.addEventListener( 'click', close );

	ui.sheetClose.addEventListener( 'click', () => closeSheet() );

	ui.unmute.addEventListener( 'click', () => {
		state.muted = false;
		ui.video.muted = false;
		ui.unmute.hidden = true;
	} );

	ui.video.addEventListener( 'timeupdate', paintProgress );
	ui.video.addEventListener( 'ended', () => {
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
		cfg: ctx.cfg || {},
		surface: ctx.surface || '',
		onSeen: ctx.onSeen || function () {},
		returnTo: document.activeElement,
	};

	document.documentElement.setAttribute( 'data-ocsp-open', '1' );
	ui.root.setAttribute( 'data-open', '1' );
	ui.close.focus( { preventScroll: true } );

	state.onSeen( story().i );
	track( 'o' );
	play();
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
