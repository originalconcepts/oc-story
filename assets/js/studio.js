/**
 * The studio.
 *
 * One screen: the stories a shop has, and the editor for one of them. It is
 * plain modules and plain DOM — no framework, no build step — because the
 * interesting engineering in this plugin is the video pipeline and the
 * storefront budget, and neither is helped by a bundler in the admin.
 *
 * The flow it is built around is video first: a shop owner has a clip and wants
 * it on the site. So "new story" opens the camera roll straight away, and the
 * caption and the products are asked for afterwards, over the footage.
 */

import { encode, isSupported } from './encoder.js';
import { upload } from './uploader.js';

const cfg = window.ocsStudio || {};
const t = cfg.i18n || {};

/* ------------------------------------------------------------------ utils */

function el( tag, attrs = {}, children = [] ) {
	const node = document.createElement( tag );

	for ( const [ key, value ] of Object.entries( attrs ) ) {
		if ( 'class' === key ) {
			node.className = value;
		} else if ( 'text' === key ) {
			node.textContent = value;
		} else if ( 'html' === key ) {
			node.innerHTML = value;
		} else if ( key.startsWith( 'on' ) ) {
			node.addEventListener( key.slice( 2 ).toLowerCase(), value );
		} else if ( false !== value && null !== value && undefined !== value ) {
			node.setAttribute( key, value );
		}
	}

	for ( const child of [].concat( children ) ) {
		if ( child ) {
			node.append( child );
		}
	}

	return node;
}

function sprintf( template, ...args ) {
	let index = 0;
	return String( template ).replace( /%(\d+\$)?[sd]/g, ( match, position ) => {
		const at = position ? parseInt( position, 10 ) - 1 : index++;
		return undefined === args[ at ] ? match : args[ at ];
	} );
}

function mb( bytes ) {
	return ( bytes / 1048576 ).toFixed( 1 ) + 'MB';
}

async function api( path, init = {} ) {
	const response = await fetch( cfg.api.root.replace( /\/$/, '' ) + path, {
		credentials: 'same-origin',
		...init,
		headers: {
			'X-WP-Nonce': cfg.api.nonce,
			...( init.body ? { 'Content-Type': 'application/json' } : {} ),
			...( init.headers || {} ),
		},
	} );

	const body = await response.json().catch( () => null );

	if ( ! response.ok ) {
		// WordPress wraps fatal errors in HTML paragraphs; strip the markup so
		// the note bar shows words rather than tags.
		const raw = ( body && body.message ) || t.failed;
		const error = new Error( String( raw ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim() );
		error.code = body && body.code;
		throw error;
	}

	return body;
}

/**
 * Ask for a video file. Resolves to null when the picker is dismissed.
 *
 * @return {Promise<?File>}
 */
function pickVideo() {
	return new Promise( ( resolve ) => {
		const input = el( 'input', { type: 'file', accept: 'video/*,image/*', style: 'display:none' } );
		input.addEventListener( 'change', () => {
			resolve( input.files[ 0 ] || null );
			input.remove();
		} );
		document.body.append( input );
		input.click();
	} );
}

/* ------------------------------------------------------------------ state */

const state = {
	stories: [],
	editing: null,
	slide: 0,
	busy: null,
	progress: 0,
	note: null,
	results: [],
	dirty: false,
};

// The studio is two things now: its own screen, and the third step of the
// wizard. Both mount the same app into a node they own — the only difference
// is that the wizard hands it a way back and takes what was made.
let root = null;
let shell = {};

/**
 * Put the studio into a node.
 *
 * @param {Element} node    Where to render.
 * @param {Object}  options { story, single, onDone } — the wizard's hooks.
 */
export function mount( node, options = {} ) {
	root = node;
	shell = options;

	state.editing = null;
	state.note = null;

	// Called from the wizard, this arrives mid-flow and the editor is a
	// moment away. Painting the list of every video in the shop in that gap
	// is a flash of somewhere the person did not ask to go, so the screen
	// they were on stays up until there is an editor to replace it with.
	if ( ! ( 'story' in options ) ) {
		render();
	}

	load().then( () => {
		if ( options.story ) {
			const found = state.stories.find( ( item ) => item.id === options.story );

			if ( found ) {
				setState( { editing: found, slide: 0, dirty: false } );
				return;
			}
		}

		if ( 'story' in options ) {
			newStory();
		}
	} );
}

function setState( patch ) {
	Object.assign( state, patch );
	render();
}

function fail( error ) {
	let message = error && error.message ? error.message : String( error );

	if ( 'ocs_no_decoder' === message ) {
		message = sprintf( t.noDecoder, error.codec || '?' );
	} else if ( 'ocs_no_webcodecs' === message || 'ocs_no_h264_encoder' === message ) {
		message = t.noEncoder;
	} else if ( message.startsWith( 'ocs_mp4_codec_' ) ) {
		message = sprintf( t.noDecoder, message.replace( 'ocs_mp4_codec_', '' ) );
	}

	setState( { busy: null, progress: 0, note: { kind: 'error', text: message } } );
}

/* ------------------------------------------------------------------ media */

/**
 * Compress a picked file and upload it, returning a slide.
 *
 * Compression is best-effort by design. A browser too old for WebCodecs still
 * gets to publish — it just uploads what it has and waits longer — because
 * "your browser is unsupported" is not an answer a shop owner can act on.
 *
 * @param {File} file Picked file.
 * @return {Promise<Object>} A slide.
 */
/**
 * A photo becomes a slide too: resized on the device, five seconds on screen,
 * and the only kind of slide where pins make sense — a frame that does not
 * move under them.
 *
 * @param {File} file Picked image.
 * @return {Promise<Object>} payload/poster/dimensions for upload().
 */
async function shrinkImage( file ) {
	const bitmap = await createImageBitmap( file );
	const cap = 1440;
	const scale = Math.min( 1, cap / Math.max( bitmap.width, bitmap.height ) );
	const width = Math.round( bitmap.width * scale );
	const height = Math.round( bitmap.height * scale );

	const canvas = document.createElement( 'canvas' );
	canvas.width = width;
	canvas.height = height;
	canvas.getContext( '2d' ).drawImage( bitmap, 0, 0, width, height );
	bitmap.close();

	const blob = await new Promise( ( resolve ) => canvas.toBlob( resolve, 'image/jpeg', 0.85 ) );
	const poster = canvas.toDataURL( 'image/jpeg', 0.7 );

	return { blob, poster, width, height };
}

async function ingest( file ) {
	let payload = file;
	let poster = '';
	let width = 0;
	let height = 0;
	let duration = 0;
	let note = null;

	if ( 0 === file.type.indexOf( 'image/' ) ) {
		setState( { busy: t.compressing, progress: 0.2, note: null } );

		const image = await shrinkImage( file );

		setState( { busy: t.uploading } );

		const uploaded = await upload( image.blob, {
			api: cfg.api,
			filename: file.name.replace( /\.[^.]+$/, '' ) + '.jpg',
			poster: image.poster,
			width: image.width,
			height: image.height,
			duration: 5,
			onProgress: ( value ) => setState( { progress: 0.2 + value * 0.8 } ),
		} );

		setState( { busy: null, progress: 0, note: null } );

		return {
			id: '',
			type: 'image',
			source: uploaded.source,
			ref: uploaded.ref,
			url: uploaded.url,
			poster: uploaded.poster,
			poster_url: uploaded.poster_url,
			w: uploaded.w,
			h: uploaded.h,
			duration: 5,
			products: [],
			cta: { text: '', url: '' },
		};
	}

	if ( cfg.encode.enabled && isSupported() ) {
		setState( { busy: t.compressing, progress: 0, note: null } );

		const result = await encode(
			file,
			{
				maxSide: cfg.encode.maxSide,
				bitrate: cfg.encode.bitrate,
				fps: cfg.encode.fps,
				maxSeconds: cfg.limits.maxSeconds,
			},
			( value ) => setState( { progress: value * 0.7 } )
		);

		payload = result.blob;
		poster = result.poster || '';
		width = result.width;
		height = result.height;
		duration = result.duration;
		note = sprintf( t.shrank, mb( result.sourceBytes ), mb( result.bytes ) );
	} else {
		if ( file.size > cfg.limits.maxBytes ) {
			throw new Error( t.tooLarge );
		}
		setState( { note: { kind: 'info', text: t.noEncoder } } );
	}

	setState( { busy: t.uploading } );

	const uploaded = await upload( payload, {
		api: cfg.api,
		filename: file.name.replace( /\.[^.]+$/, '' ) + '.mp4',
		poster,
		width,
		height,
		duration,
		onProgress: ( value ) => setState( { progress: 0.7 + value * 0.3 } ),
	} );

	setState( {
		busy: null,
		progress: 0,
		note: note ? { kind: 'info', text: note } : null,
	} );

	return {
		id: '',
		type: 'video',
		source: uploaded.source,
		ref: uploaded.ref,
		url: uploaded.url,
		poster: uploaded.poster,
		poster_url: uploaded.poster_url,
		w: uploaded.w,
		h: uploaded.h,
		duration: uploaded.duration,
		products: [],
		cta: { text: '', url: '' },
	};
}

/* ------------------------------------------------------------------ verbs */

async function load() {
	try {
		setState( { stories: await api( '/admin/stories' ) } );
	} catch ( e ) {
		fail( e );
	}
}

async function newStory() {
	const file = await pickVideo();

	if ( ! file ) {
		// Nothing chosen. Inside the wizard that means "never mind", so hand
		// the screen back rather than leaving an empty editor behind.
		if ( shell.onDone ) {
			done();
		}

		return;
	}

	try {
		const slide = await ingest( file );
		const story = await api( '/admin/stories', {
			method: 'POST',
			body: JSON.stringify( { title: '', status: 'draft', slides: [ trim( slide ) ] } ),
		} );

		state.stories.unshift( story );
		setState( { editing: story, slide: 0, dirty: false } );
	} catch ( e ) {
		fail( e );
	}
}

async function addSlide() {
	const file = await pickVideo();
	if ( ! file ) {
		return;
	}

	try {
		const slide = await ingest( file );
		state.editing.slides.push( slide );
		setState( { slide: state.editing.slides.length - 1, dirty: true } );
	} catch ( e ) {
		fail( e );
	}
}

/**
 * Reduce a slide to what the server stores. Names and prices are resolved on
 * every read and must never travel back.
 *
 * @param {Object} slide Slide.
 * @return {Object}
 */
function trim( slide ) {
	return {
		id: slide.id || '',
		type: slide.type || 'video',
		source: slide.source,
		ref: slide.ref,
		poster: slide.poster,
		w: slide.w,
		h: slide.h,
		duration: slide.duration,
		products: ( slide.products || [] ).map( ( p ) => ( { id: p.id, x: p.x, y: p.y } ) ),
		cta: slide.cta || { text: '', url: '' },
	};
}

async function save() {
	const story = state.editing;
	if ( ! story ) {
		return;
	}

	setState( { busy: t.saving } );

	try {
		const saved = await api( '/admin/stories/' + story.id, {
			method: 'POST',
			body: JSON.stringify( {
				title: story.title,
				status: story.status,
				collection: story.collection || '',
				life: story.life || 0,
				slides: story.slides.map( trim ),
			} ),
		} );

		const index = state.stories.findIndex( ( s ) => s.id === saved.id );
		if ( index > -1 ) {
			state.stories[ index ] = saved;
		}

		setState( { editing: saved, busy: null, dirty: false, note: { kind: 'info', text: t.saved } } );
	} catch ( e ) {
		fail( e );
	}
}

async function destroy() {
	const story = state.editing;
	if ( ! story || ! window.confirm( t.confirmDelete ) ) {
		return;
	}

	try {
		await api( '/admin/stories/' + story.id, { method: 'DELETE' } );
		setState( {
			stories: state.stories.filter( ( s ) => s.id !== story.id ),
			editing: null,
		} );
	} catch ( e ) {
		fail( e );
	}
}

async function reorder( ids ) {
	try {
		await api( '/admin/stories/reorder', { method: 'POST', body: JSON.stringify( { ids } ) } );
	} catch ( e ) {
		fail( e );
	}
}

let searchTimer = null;

/**
 * Redraw only the results list, in place.
 *
 * Typing must never trigger a full render: replacing the DOM replaces the
 * input mid-word, which throws the keyboard focus out after the first letter.
 * The panel re-registers this painter on every render, so it always points at
 * the list currently on screen.
 */
let paintResults = () => {};

function searchProducts( term ) {
	clearTimeout( searchTimer );

	if ( term.trim().length < 2 ) {
		state.results = [];
		paintResults();
		return;
	}

	searchTimer = setTimeout( async () => {
		try {
			state.results = await api( '/admin/products?search=' + encodeURIComponent( term ) );
			paintResults();
		} catch ( e ) {
			fail( e );
		}
	}, 250 );
}

function tagProduct( product ) {
	const slide = state.editing.slides[ state.slide ];

	if ( slide.products.some( ( p ) => p.id === product.id ) ) {
		return;
	}

	slide.products.push( { ...product, x: null, y: null } );
	setState( { results: [], dirty: true } );
}

function untagProduct( id ) {
	const slide = state.editing.slides[ state.slide ];
	slide.products = slide.products.filter( ( p ) => p.id !== id );
	setState( { dirty: true } );
}

function removeSlide() {
	const story = state.editing;

	story.slides.splice( state.slide, 1 );
	setState( { slide: Math.max( 0, state.slide - 1 ), dirty: true } );
}

/* ------------------------------------------------------------------- pins */

/**
 * Dragging a tag onto the frame.
 *
 * The coordinates are fractions of the video frame, left-origin and physical:
 * the frame itself never mirrors in RTL, so neither do they. Listeners sit on
 * the document rather than using pointer capture, so a finger that wanders off
 * the pin mid-drag keeps dragging instead of dropping.
 */
let dragging = null;

function startPinDrag( event, product, pin ) {
	const stage = pin.closest( '.ocs-stage' );

	if ( ! stage ) {
		return;
	}

	event.preventDefault();
	dragging = { product, pin, rect: stage.getBoundingClientRect() };
}

document.addEventListener( 'pointermove', ( e ) => {
	if ( ! dragging ) {
		return;
	}

	const x = Math.min( 1, Math.max( 0, ( e.clientX - dragging.rect.left ) / dragging.rect.width ) );
	const y = Math.min( 1, Math.max( 0, ( e.clientY - dragging.rect.top ) / dragging.rect.height ) );

	dragging.product.x = Math.round( x * 10000 ) / 10000;
	dragging.product.y = Math.round( y * 10000 ) / 10000;

	// The DOM is moved directly during the drag; one render at the drop is
	// enough, and re-rendering per move would tear the pointer off the pin.
	dragging.pin.style.left = x * 100 + '%';
	dragging.pin.style.top = y * 100 + '%';
} );

document.addEventListener( 'pointerup', () => {
	if ( dragging ) {
		dragging = null;
		setState( { dirty: true } );
	}
} );

function stagePins( slide ) {
	const pinned = ( slide.products || [] ).filter( ( p ) => null !== p.x && null !== p.y );

	return el( 'div', { class: 'ocs-stage__pins' }, pinned.map( ( product, index ) => {
		const pin = el( 'button', {
			class: 'ocs-stage__pin',
			type: 'button',
			title: product.name || '',
			text: String( index + 1 ),
			style: 'left:' + product.x * 100 + '%;top:' + product.y * 100 + '%',
		} );

		pin.addEventListener( 'pointerdown', ( e ) => startPinDrag( e, product, pin ) );

		return pin;
	} ) );
}

/* ----------------------------------------------------------------- render */

function noteBar() {
	if ( state.busy ) {
		return el( 'div', { class: 'ocs-note ocs-note--busy' }, [
			el( 'div', { text: state.busy } ),
			el( 'div', { class: 'ocs-progress' }, [
				el( 'span', { style: 'inline-size:' + Math.round( state.progress * 100 ) + '%' } ),
			] ),
		] );
	}

	if ( state.note ) {
		return el( 'div', {
			class: 'ocs-note' + ( 'error' === state.note.kind ? ' ocs-note--error' : '' ),
			text: state.note.text,
		} );
	}

	return null;
}

function storyCard( story, index ) {
	const card = el( 'button', {
		class: 'ocs-card',
		type: 'button',
		draggable: 'true',
		title: t.dragHint,
		onClick: () => setState( { editing: story, slide: 0, dirty: false, note: null } ),
	}, [
		story.poster_url
			? el( 'img', { class: 'ocs-card__poster', src: story.poster_url, alt: '', loading: 'lazy' } )
			: el( 'div', { class: 'ocs-card__poster' } ),
		el( 'div', { class: 'ocs-card__body' }, [
			el( 'div', { class: 'ocs-card__title', text: story.title || cfg.labels.untitled } ),
			el( 'div', { class: 'ocs-card__meta' }, [
				el( 'span', {
					class: 'ocs-pill' + ( 'publish' === story.status ? ' ocs-pill--live' : '' ),
					text: 'publish' === story.status ? t.published : t.draft,
				} ),
				el( 'span', { text: story.slides.length + ' · ' + t.slides } ),
				story.views7d > 0 ? el( 'span', { text: story.views7d + ' · ' + t.views7d } ) : null,
			] ),
		] ),
	] );

	card.dataset.index = index;

	card.addEventListener( 'dragstart', ( e ) => {
		e.dataTransfer.setData( 'text/plain', String( index ) );
		card.dataset.dragging = '1';
	} );
	card.addEventListener( 'dragend', () => delete card.dataset.dragging );
	card.addEventListener( 'dragover', ( e ) => {
		e.preventDefault();
		card.dataset.over = '1';
	} );
	card.addEventListener( 'dragleave', () => delete card.dataset.over );
	card.addEventListener( 'drop', ( e ) => {
		e.preventDefault();
		delete card.dataset.over;

		const from = parseInt( e.dataTransfer.getData( 'text/plain' ), 10 );
		if ( Number.isNaN( from ) || from === index ) {
			return;
		}

		const list = state.stories.slice();
		list.splice( index, 0, list.splice( from, 1 )[ 0 ] );

		setState( { stories: list } );
		reorder( list.map( ( s ) => s.id ) );
	} );

	return card;
}

function listView() {
	return el( 'div', {}, [
		el( 'div', { class: 'ocs-head' }, [
			el( 'h1', { text: t.studio } ),
			el( 'button', {
				class: 'ocs-btn ocs-btn--primary',
				type: 'button',
				disabled: !! state.busy,
				text: t.newStory,
				onClick: newStory,
			} ),
		] ),
		noteBar(),
		state.stories.length
			? el( 'div', { class: 'ocs-grid' }, state.stories.map( storyCard ) )
			: el( 'div', { class: 'ocs-empty' }, [
				el( 'h2', { text: t.empty } ),
				el( 'p', { text: t.emptyHint } ),
			] ),
	] );
}

function slideStrip() {
	const story = state.editing;

	const slides = story.slides.map( ( slide, index ) => el( 'button', {
		class: 'ocs-slide',
		type: 'button',
		'aria-selected': index === state.slide ? 'true' : 'false',
		style: slide.poster_url ? 'background-image:url(' + slide.poster_url + ')' : '',
		onClick: () => setState( { slide: index, results: [] } ),
	} ) );

	// A slider or a wall shows one video per card, so its editor has nothing
	// to add a second slide to. A story is a sequence and keeps the plus.
	if ( ! shell.single ) {
		slides.push( el( 'button', {
			class: 'ocs-slide ocs-slide--add',
			type: 'button',
			text: '+',
			title: t.addSlide,
			disabled: !! state.busy,
			onClick: addSlide,
		} ) );
	}

	// One slide and no way to add another is not a strip, it is a row of one.
	if ( shell.single && slides.length < 2 ) {
		return null;
	}

	return el( 'div', { class: 'ocs-strip' }, slides );
}

function productPanel() {
	const slide = state.editing.slides[ state.slide ];

	if ( ! slide ) {
		return null;
	}

	const search = el( 'input', {
		class: 'ocs-input',
		type: 'search',
		placeholder: t.searchProducts,
		onInput: ( e ) => searchProducts( e.target.value ),
	} );

	const results = el( 'ul', { class: 'ocs-results' } );

	const buildResults = () => {
		results.replaceChildren( ...state.results.map( ( product ) => el( 'li', {}, [
			el( 'button', { class: 'ocs-result', type: 'button', onClick: () => tagProduct( product ) }, [
				product.thumb
					? el( 'img', { class: 'ocs-thumb', src: product.thumb, alt: '' } )
					: el( 'span', { class: 'ocs-thumb' } ),
				el( 'span', { class: 'ocs-result__name', text: product.name } ),
				el( 'span', { class: 'ocs-result__price', text: product.price } ),
			] ),
		] ) ) );
	};

	buildResults();
	paintResults = buildResults;

	// Pins only make sense on a still frame: on video the frame moves while a
	// pin cannot, which reads as a mistake. The player enforces the same rule.
	const pinnable = 'image' === slide.type;

	const tags = el( 'div', { class: 'ocs-tags' }, slide.products.length ? slide.products.map( ( product, index ) => {
		const pinned = pinnable && null !== product.x && null !== product.y;

		return el( 'div', { class: 'ocs-tag' }, [
			product.thumb
				? el( 'img', { class: 'ocs-thumb', src: product.thumb, alt: '' } )
				: el( 'span', { class: 'ocs-thumb' } ),
			el( 'span', { class: 'ocs-tag__name', text: product.name } ),
			pinnable ? el( 'button', {
				class: 'ocs-btn ocs-btn--ghost ocs-tag__pin' + ( pinned ? ' is-on' : '' ),
				type: 'button',
				text: pinned ? String( index + 1 ) : '+',
				title: pinned ? t.unpin : t.pin,
				'aria-label': pinned ? t.unpin : t.pin,
				'aria-pressed': pinned ? 'true' : 'false',
				onClick: () => {
					// Placing drops the pin mid-frame; dragging does the rest.
					product.x = pinned ? null : 0.5;
					product.y = pinned ? null : 0.5;
					setState( { dirty: true } );
				},
			} ) : null,
			el( 'button', {
				class: 'ocs-btn ocs-btn--ghost',
				type: 'button',
				text: '×',
				'aria-label': t.close,
				onClick: () => untagProduct( product.id ),
			} ),
		] );
	} ) : [ el( 'p', { class: 'ocs-hint', text: t.noneTagged } ) ] );

	// Order matters here. Tagged products sit directly under their own label,
	// and the search box comes last with its results hanging off it. The other
	// way round, the dropdown opens between the label and the list and the two
	// become impossible to tell apart.
	return el( 'div', { class: 'ocs-field' }, [
		el( 'label', { text: t.products } ),
		tags,
		el( 'div', { class: 'ocs-search' }, [ search, results ] ),
	] );
}

function editorView() {
	const story = state.editing;
	const slide = story.slides[ state.slide ];

	return el( 'div', {}, [
		el( 'div', { class: 'ocs-head' }, [
			el( 'button', {
				class: 'ocs-btn ocs-btn--ghost',
				type: 'button',
				// Inside the wizard the way out is back to the gallery being
				// built, not to a list of every video in the shop.
				text: '← ' + ( shell.onDone ? ( t.backToGallery || t.studio ) : t.studio ),
				onClick: () => {
					if ( shell.onDone ) {
						done();
						return;
					}

					setState( { editing: null, note: null, results: [] } );
				},
			} ),
		] ),
		noteBar(),
		el( 'div', { class: 'ocs-editor' }, [
			el( 'div', { class: 'ocs-editor__head' }, [
				el( 'strong', { text: story.title || cfg.labels.untitled } ),
				// Inside the wizard the video's own published state is not the
				// person's business — the gallery decides that when it is
				// published, and a second publish button here asking the same
				// question a different way is where George got stuck. So the
				// state pill and its toggle belong to the standalone screen
				// only, and here there is one button that finishes the job.
				shell.onDone
					? null
					: el( 'span', {
						class: 'ocs-pill' + ( 'publish' === story.status ? ' ocs-pill--live' : '' ),
						text: 'publish' === story.status ? t.published : t.draft,
					} ),
				shell.onDone
					? null
					: el( 'button', {
						class: 'ocs-btn' + ( 'publish' === story.status ? '' : ' ocs-btn--primary' ),
						type: 'button',
						disabled: !! state.busy,
						text: 'publish' === story.status ? t.unpublish : t.publish,
						// Publishing IS the save. A button that says "publish"
						// and quietly waits for a second button is how a story
						// stays a draft while its owner is looking at the shop
						// wondering where it is — which is exactly what
						// happened.
						onClick: () => {
							story.status = 'publish' === story.status ? 'draft' : 'publish';
							state.dirty = true;
							save();
						},
					} ),
				el( 'button', {
					class: 'ocs-btn ocs-btn--primary',
					type: 'button',
					disabled: !! state.busy,
					text: shell.onDone ? ( t.saveAndBack || t.save ) : ( state.dirty ? t.save : t.saved ),
					onClick: shell.onDone
						? () => {
							save().then( () => done() );
						}
						: save,
				} ),
			] ),
			el( 'div', { class: 'ocs-editor__body' }, [
				el( 'div', {}, [
					el( 'div', { class: 'ocs-stage' }, [
						slide && slide.url && 'image' === slide.type
							? el( 'img', { class: 'ocs-stage__photo', src: slide.url, alt: '' } )
							: null,
						slide && slide.url && 'image' !== slide.type
							? el( 'video', {
								src: slide.url,
								poster: slide.poster_url || '',
								controls: 'controls',
								playsinline: 'playsinline',
								preload: 'metadata',
							} )
							: null,
						slide && 'image' === slide.type ? stagePins( slide ) : null,
						slide && 'image' === slide.type && slide.products.some( ( p ) => null !== p.x )
							? el( 'p', { class: 'ocs-stage__hint', text: t.pinHint } )
							: null,
					] ),
					( () => {
						const strip = slideStrip();

						return strip
							? el( 'div', { class: 'ocs-field', style: 'margin-block-start:12px' }, [
								el( 'label', { text: t.slides } ),
								strip,
							] )
							: null;
					} )(),
				] ),
				el( 'div', {}, [
					el( 'div', { class: 'ocs-field' }, [
						el( 'label', { for: 'ocs-title', text: t.title } ),
						el( 'input', {
							id: 'ocs-title',
							class: 'ocs-input',
							type: 'text',
							maxlength: '60',
							value: story.title,
							onInput: ( e ) => {
								story.title = e.target.value;
								state.dirty = true;
							},
						} ),
					] ),
					// How long it stays up. Forever unless somebody says
					// otherwise, and what "otherwise" does is take it off the
					// air — the video, its slides and its tagged products all
					// stay exactly where they are.
					el( 'div', { class: 'ocs-field' }, [
						el( 'label', { for: 'ocs-life', text: t.life } ),
						el( 'select', {
							id: 'ocs-life',
							class: 'ocs-input',
							onChange: ( e ) => {
								story.life = parseInt( e.target.value, 10 ) || 0;
								state.dirty = true;
								setState( {} );
							},
						}, [
							el( 'option', { value: '0', text: t.lifeForever, selected: ! story.life } ),
							el( 'option', { value: '86400', text: t.lifeDay, selected: 86400 === story.life } ),
						] ),
						story.expires
							? el( 'span', {
								class: 'ocs-field__note',
								text: t.lifeUntil.replace( '%s', new Date( story.expires * 1000 ).toLocaleString() ),
							} )
							: null,
						! story.expires && story.life && 'publish' !== story.status
							? el( 'span', { class: 'ocs-field__note', text: t.lifeDown } )
							: null,
					] ),
					el( 'div', { class: 'ocs-field' }, [
						el( 'label', { for: 'ocs-collection', text: t.collection } ),
						el( 'input', {
							id: 'ocs-collection',
							class: 'ocs-input',
							type: 'text',
							maxlength: '60',
							list: 'ocs-collections',
							value: story.collection || '',
							placeholder: t.collectionHint,
							onInput: ( e ) => {
								story.collection = e.target.value;
								state.dirty = true;
							},
						} ),
						el( 'datalist', { id: 'ocs-collections' },
							[ ...new Set( state.stories.map( ( s ) => s.collection ).filter( Boolean ) ) ]
								.map( ( name ) => el( 'option', { value: name } ) )
						),
					] ),
					productPanel(),
					el( 'div', { class: 'ocs-actions' }, [
						slide && story.slides.length > 1
							? el( 'button', {
								class: 'ocs-btn',
								type: 'button',
								text: t.deleteSlide,
								onClick: removeSlide,
							} )
							: null,
						el( 'button', {
							class: 'ocs-btn ocs-btn--danger',
							type: 'button',
							text: t.delete,
							onClick: destroy,
						} ),
					] ),
				] ),
			] ),
		] ),
	] );
}

function render() {
	if ( ! root ) {
		return;
	}

	// Inside the wizard there is no library to show. Rendering one while the
	// file dialog was still open put the whole video list behind it, and
	// clicking anything in it opened a second editor on top — which is what
	// made step three unusable.
	if ( shell.onDone && ! state.editing ) {
		root.replaceChildren( el( 'div', { class: 'ocs-boot', text: t.saving || '' } ) );
		return;
	}

	root.replaceChildren( state.editing ? editorView() : listView() );
	root.removeAttribute( 'data-loading' );
}

/**
 * Leave the studio, handing back whatever was being edited.
 *
 * Only the wizard has somewhere to go; on its own screen this is not drawn.
 */
function done() {
	const made = state.editing;

	state.editing = null;

	// Let go of the node before handing back. A save or a load still in
	// flight would otherwise finish later and repaint the editor over the
	// screen the wizard has already put back.
	root = null;

	const back = shell.onDone;
	shell = {};

	if ( 'function' === typeof back ) {
		back( made );
	}
}

/* ------------------------------------------------------------------- boot */

// Leaving with unsaved edits loses a caption or a tag, never a video: the
// upload is committed the moment it finishes, before anything is saved.
window.addEventListener( 'beforeunload', ( e ) => {
	if ( state.dirty ) {
		e.preventDefault();
		e.returnValue = '';
	}
} );

const own = document.getElementById( 'ocs-studio' );

if ( own ) {
	mount( own );
}
