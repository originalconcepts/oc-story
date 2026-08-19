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

	stage.append( video, pins, bars, top, unmute, prev, next, products, ahead );
	root.append( stage );

	return { root, stage, video, ahead, bars, title, close, unmute, prev, next, products, pins };
}

/* ------------------------------------------------------------------ paint */

function story() {
	return state.stories[ state.si ];
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

	ui.products.replaceChildren(
		...list.map( ( product ) => {
			const card = el( 'a', 'ocsp__product', { href: product.u } );

			if ( product.t ) {
				card.append( el( 'img', '', { src: product.t, alt: '', loading: 'lazy' } ) );
			}

			const text = el( 'span' );
			text.append( el( 'b', '', { text: product.n } ), el( 'span', '', { text: product.p } ) );
			card.append( text );

			card.addEventListener( 'click', () => {
				attribute( product );
				track( 'p', { l: slide().i } );
			} );

			return card;
		} )
	);

	// Pins mark a product's spot in the frame when the owner placed one.
	ui.pins.replaceChildren(
		...list
			.filter( ( product ) => null !== product.x && null !== product.y )
			.map( ( product ) => el( 'span', 'ocsp__pin', {
				style: 'left:' + product.x * 100 + '%;top:' + product.y * 100 + '%',
			} ) )
	);
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
	const total = ui.video.duration || ( current && current.d ) || 0;

	if ( bar && total ) {
		bar.firstChild.style.width = Math.min( 100, ( ui.video.currentTime / total ) * 100 ) + '%';
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
	ui.video.poster = current.p || '';
	ui.video.src = current.u;

	paintBars();
	paintProducts();

	// The tap that opened the player is the user gesture iOS requires, so sound
	// is allowed. If the browser disagrees anyway, fall back to muted and offer
	// a control rather than failing to play at all.
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

	cancelAnimationFrame( raf );
	raf = requestAnimationFrame( tick );

	const ahead = story().s[ state.qi + 1 ];
	ui.ahead.src = ahead ? ahead.u : '';
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
			ui.video.pause();
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
			ui.video.play().catch( () => {} );
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

	if ( 'Escape' === e.key ) {
		close();
	} else if ( 'ArrowRight' === e.key ) {
		go( RTL() ? -1 : 1 );
	} else if ( 'ArrowLeft' === e.key ) {
		go( RTL() ? 1 : -1 );
	} else if ( ' ' === e.key ) {
		e.preventDefault();
		if ( ui.video.paused ) {
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
