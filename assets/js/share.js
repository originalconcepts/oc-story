/**
 * Adding a video from a phone, without going near wp-admin.
 *
 * One screen, one job, one thumb. The shop owner's videos are on their phone
 * and the admin is not where anybody wants to be while holding one, so this
 * is the whole of what they need: choose a video, name it, say which products
 * are in it, send.
 *
 * It compresses in the browser first, exactly as the studio does, because the
 * alternative is a hundred-megabyte upload over a phone's data connection.
 */

import { encode, isSupported } from './encoder.js';
import { upload } from './uploader.js';

const cfg = window.ocsShare || {};
const t = cfg.i18n || {};
const KEY = 'ocs_share_device';

/* ------------------------------------------------------------------ tiny */

function el( tag, attrs = {}, children = [] ) {
	const node = document.createElement( tag );

	for ( const [ key, value ] of Object.entries( attrs ) ) {
		if ( 'text' === key ) {
			node.textContent = value;
		} else if ( key.startsWith( 'on' ) && 'function' === typeof value ) {
			node.addEventListener( key.slice( 2 ).toLowerCase(), value );
		} else if ( false !== value && null !== value && undefined !== value ) {
			node.setAttribute( key, true === value ? '' : value );
		}
	}

	for ( const child of [].concat( children ) ) {
		if ( child ) {
			node.append( child );
		}
	}

	return node;
}

/**
 * The secret this phone was given when it claimed the link.
 *
 * Kept per link rather than per shop, so a second link on the same phone is
 * a second key rather than a collision.
 *
 * @return {string} The secret, or ''.
 */
function device() {
	try {
		return localStorage.getItem( KEY + '_' + cfg.token.slice( 0, 8 ) ) || '';
	} catch ( e ) {
		return '';
	}
}

function keep( secret ) {
	try {
		localStorage.setItem( KEY + '_' + cfg.token.slice( 0, 8 ), secret );
	} catch ( e ) {}
}

async function api( path, init = {} ) {
	const url = cfg.api.replace( /\/$/, '' ) + path
		+ ( path.includes( '?' ) ? '&' : '?' ) + 'token=' + encodeURIComponent( cfg.token );

	const response = await fetch( url, {
		credentials: 'omit',
		...init,
		headers: {
			'X-OCS-Device': device(),
			...( init.headers || {} ),
		},
	} );

	const body = await response.json().catch( () => null );

	if ( ! response.ok ) {
		const error = new Error( ( body && body.message ) || t.failed );
		error.code = body && body.code;
		throw error;
	}

	return body;
}

/* ---------------------------------------------------------------- state */

const state = {
	view: 'loading',
	message: '',
	gallery: null,
	stories: [],
	limits: null,
	hold: false,
	slide: null,
	title: '',
	products: [],
	results: [],
	joinStory: 0,
	busy: '',
	progress: 0,
};

const root = document.getElementById( 'ocs-share' );

function setState( patch ) {
	Object.assign( state, patch );
	render();
}

/* ----------------------------------------------------------------- boot */

async function start() {
	try {
		// A link nobody has opened yet belongs to whoever opens it first, and
		// that is meant to be this phone, a minute after it was sent.
		if ( ! device() ) {
			setState( { view: 'loading', message: t.claiming } );

			const claimed = await api( '/share/claim', { method: 'POST' } );

			keep( claimed.device );
		}

		const info = await api( '/share/gallery' );

		setState( {
			view: 'ready',
			gallery: info.gallery,
			stories: info.stories || [],
			limits: info.limits,
			hold: !! info.hold,
			joinStory: 0,
		} );
	} catch ( error ) {
		setState( { view: 'closed', message: error.message } );
	}
}

/* ------------------------------------------------------------- the work */

function pickFile() {
	const input = el( 'input', { type: 'file', accept: 'video/*', capture: false } );

	input.addEventListener( 'change', () => {
		const file = input.files && input.files[0];

		if ( file ) {
			take( file );
		}
	} );

	input.click();
}

/**
 * Shrink a video and send it, then hold it until the form is filled in.
 *
 * @param {File} file The chosen video.
 */
async function take( file ) {
	if ( state.limits && file.size > state.limits.max_bytes * 6 ) {
		setState( { message: t.tooBig } );
		return;
	}

	try {
		let blob = file;
		let poster = '';
		let width = 0;
		let height = 0;
		let seconds = 0;

		if ( state.limits.encode.enabled && isSupported() ) {
			setState( { busy: t.shrinking, progress: 0, message: '' } );

			const made = await encode(
				file,
				{
					maxSide: state.limits.encode.max_side,
					bitrate: state.limits.encode.bitrate,
					fps: state.limits.encode.fps,
					audioBitrate: state.limits.encode.audio,
				},
				( value ) => setState( { progress: value * 0.6 } )
			);

			blob = made.blob;
			poster = made.poster;
			width = made.width;
			height = made.height;
			seconds = made.duration;
		}

		if ( seconds && state.limits.max_seconds && seconds > state.limits.max_seconds + 1 ) {
			setState( { busy: '', progress: 0, message: t.tooLong } );
			return;
		}

		setState( { busy: t.sending } );

		const sent = await upload( blob, {
			api: {
				root: cfg.api,
				base: '/share/upload',
				query: 'token=' + encodeURIComponent( cfg.token ),
				headers: { 'X-OCS-Device': device() },
			},
			filename: ( file.name || 'video' ).replace( /\.[^.]+$/, '' ) + '.mp4',
			poster,
			width,
			height,
			duration: seconds,
			onProgress: ( value ) => setState( { progress: 0.6 + value * 0.4 } ),
		} );

		setState( {
			busy: '',
			progress: 0,
			slide: {
				type: 'video',
				source: sent.source,
				ref: sent.ref,
				poster: sent.poster,
				w: sent.w,
				h: sent.h,
				duration: sent.duration || seconds,
				products: [],
			},
		} );
	} catch ( error ) {
		setState( { busy: '', progress: 0, message: error.message || t.failed } );
	}
}

let searchTimer = null;
let paintResults = () => {};

/**
 * Which products are in the video.
 *
 * @return {Element} The field.
 */
function productField() {
	const results = el( 'div', { class: 'ocs-sh__results' } );

	paintResults = () => {
		results.replaceChildren(
			...state.results.map( ( product ) =>
				el( 'button', {
					class: 'ocs-sh__result',
					type: 'button',
					onClick: () => {
						if ( ! state.products.some( ( p ) => p.id === product.id ) ) {
							state.products.push( product );
						}

						state.results = [];
						setState( {} );
					},
				}, [
					product.thumb ? el( 'img', { src: product.thumb, alt: '' } ) : null,
					el( 'span', { text: product.name } ),
				] )
			)
		);
	};

	const input = el( 'input', {
		class: 'ocs-sh__input',
		type: 'search',
		placeholder: t.search,
		onInput: ( e ) => {
			const term = e.target.value.trim();

			clearTimeout( searchTimer );

			if ( term.length < 2 ) {
				state.results = [];
				paintResults();
				return;
			}

			searchTimer = setTimeout( async () => {
				try {
					state.results = await api( '/share/products?search=' + encodeURIComponent( term ) );
				} catch ( error ) {
					state.results = [];
				}

				paintResults();
			}, 250 );
		},
	} );

	return el( 'div', {}, [
		el( 'label', { class: 'ocs-sh__label', text: t.products } ),
		el(
			'div',
			{ class: 'ocs-sh__chips' },
			state.products.map( ( product ) =>
				el( 'span', { class: 'ocs-sh__chip' }, [
					el( 'span', { text: product.name } ),
					el( 'button', {
						class: 'ocs-sh__chip-x',
						type: 'button',
						'aria-label': t.remove,
						text: '×',
						onClick: () => {
							state.products = state.products.filter( ( p ) => p.id !== product.id );
							setState( {} );
						},
					} ),
				] )
			)
		),
		input,
		results,
	] );
}

/**
 * Send the finished thing.
 */
async function submit() {
	setState( { busy: t.sending, message: '' } );

	try {
		const answer = await api( '/share/video', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				story: state.joinStory || 0,
				title: state.title,
				slide: {
					...state.slide,
					products: state.products.map( ( p ) => ( { id: p.id } ) ),
				},
			} ),
		} );

		setState( { view: 'done', busy: '', hold: !! answer.held } );
	} catch ( error ) {
		setState( { busy: '', message: error.message || t.failed } );
	}
}

/* ---------------------------------------------------------------- views */

function shell( children ) {
	return el( 'div', { class: 'ocs-sh' }, [
		el( 'header', { class: 'ocs-sh__top' }, [
			el( 'span', { class: 'ocs-sh__shop', text: cfg.shop || '' } ),
			state.gallery ? el( 'h1', { text: state.gallery.label } ) : el( 'h1', { text: t.title } ),
		] ),
		state.message ? el( 'p', { class: 'ocs-sh__warn', text: state.message } ) : null,
		...[].concat( children ),
	] );
}

function busyView() {
	return shell(
		el( 'div', { class: 'ocs-sh__busy' }, [
			el( 'div', { class: 'ocs-sh__bar' }, [
				el( 'i', { style: 'width:' + Math.round( state.progress * 100 ) + '%' } ),
			] ),
			el( 'p', { text: state.busy } ),
		] )
	);
}

function readyView() {
	if ( state.busy ) {
		return busyView();
	}

	if ( ! state.slide ) {
		return shell( [
			el( 'button', { class: 'ocs-sh__pick', type: 'button', onClick: pickFile }, [
				el( 'span', { class: 'ocs-sh__pick-mark', text: '+' } ),
				el( 'span', { text: t.pick } ),
			] ),
		] );
	}

	return shell( [
		el( 'div', { class: 'ocs-sh__made' }, [
			state.slide.poster_url
				? el( 'img', { src: state.slide.poster_url, alt: '' } )
				: el( 'span', { class: 'ocs-sh__blank' } ),
			el( 'button', {
				class: 'ocs-sh__again',
				type: 'button',
				text: t.pickAgain,
				onClick: pickFile,
			} ),
		] ),

		// A story gallery is a set of series, so a new video either joins one
		// or begins another.
		state.gallery && state.gallery.series && state.stories.length
			? el( 'div', {}, [
				el( 'label', { class: 'ocs-sh__label', text: t.whichStory } ),
				el(
					'div',
					{ class: 'ocs-sh__stories' },
					[
						el( 'button', {
							class: 'ocs-sh__story' + ( state.joinStory ? '' : ' is-on' ),
							type: 'button',
							onClick: () => setState( { joinStory: 0 } ),
						}, [ el( 'span', { class: 'ocs-sh__story-new', text: '+' } ), el( 'span', { text: t.newStory } ) ] ),
					].concat(
						state.stories.map( ( story ) =>
							el( 'button', {
								class: 'ocs-sh__story' + ( state.joinStory === story.id ? ' is-on' : '' ),
								type: 'button',
								onClick: () => setState( { joinStory: story.id } ),
							}, [
								story.thumb ? el( 'img', { src: story.thumb, alt: '' } ) : el( 'span', { class: 'ocs-sh__blank' } ),
								el( 'span', { text: story.title || '' } ),
								el( 'small', { text: t.storySlides.replace( '%d', String( story.slides ) ) } ),
							] )
						)
					)
				),
			] )
			: null,

		state.joinStory
			? null
			: el( 'div', {}, [
				el( 'label', { class: 'ocs-sh__label', for: 'ocs-sh-title', text: t.caption } ),
				el( 'input', {
					id: 'ocs-sh-title',
					class: 'ocs-sh__input',
					type: 'text',
					maxlength: '60',
					value: state.title,
					onInput: ( e ) => {
						state.title = e.target.value;
					},
				} ),
				el( 'span', { class: 'ocs-sh__note', text: t.captionNote } ),
			] ),

		productField(),

		el( 'button', {
			class: 'ocs-sh__send',
			type: 'button',
			text: t.send,
			onClick: submit,
		} ),
	] );
}

function doneView() {
	return shell( [
		el( 'div', { class: 'ocs-sh__done' }, [
			el( 'span', { class: 'ocs-sh__tick', text: '✓' } ),
			el( 'h2', { text: t.done } ),
			el( 'p', { text: state.hold ? t.doneHeld : t.doneLive } ),
			el( 'button', {
				class: 'ocs-sh__send',
				type: 'button',
				text: t.another,
				onClick: () => setState( {
					view: 'ready',
					slide: null,
					title: '',
					products: [],
					results: [],
					joinStory: 0,
					message: '',
				} ),
			} ),
		] ),
	] );
}

function render() {
	if ( ! root ) {
		return;
	}

	if ( 'loading' === state.view ) {
		root.replaceChildren( shell( el( 'p', { class: 'ocs-sh__busy', text: state.message || t.checking } ) ) );
		return;
	}

	if ( 'closed' === state.view ) {
		root.replaceChildren(
			el( 'div', { class: 'ocs-sh' }, [
				el( 'div', { class: 'ocs-sh__done' }, [
					el( 'span', { class: 'ocs-sh__tick ocs-sh__tick--no', text: '!' } ),
					el( 'p', { text: state.message } ),
				] ),
			] )
		);
		return;
	}

	root.replaceChildren( 'done' === state.view ? doneView() : readyView() );
	root.removeAttribute( 'data-loading' );
}

render();
start();
