/**
 * Making a gallery: three questions, in the order a person asks them.
 *
 * What used to be here was two screens joined by a concept. You made a video
 * series in one place, a widget in another, and the widget had to be told
 * which series to show. Nobody who owns a shop thinks in joins — the evidence
 * is that the person who commissioned this plugin built that link twice and
 * saw nothing appear either time.
 *
 * So: one thing called a gallery, made by answering what it is, where it
 * goes, and what is in it. Underneath, the two objects and the link between
 * them are exactly as they were. Only the question changed.
 */

const cfg = window.ocsWizard || {};
const t = cfg.i18n || {};

/* ------------------------------------------------------------------ tiny */

function el( tag, attrs = {}, children = [] ) {
	const node = document.createElement( tag );

	for ( const [ key, value ] of Object.entries( attrs ) ) {
		if ( 'text' === key ) {
			node.textContent = value;
		} else if ( 'html' === key ) {
			node.innerHTML = value;
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

async function api( path, init = {} ) {
	const response = await fetch( cfg.api.root.replace( /\/$/, '' ) + path, {
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.api.nonce },
		...init,
	} );

	const body = await response.json().catch( () => null );

	if ( ! response.ok ) {
		throw new Error( ( body && body.message ) || response.statusText );
	}

	return body;
}

/* ------------------------------------------------------------------ art */

/**
 * A drawing of the page with the gallery in the spot being offered.
 *
 * This is the part that has to be honest. A picture that says "it will look
 * like this" is a promise, and a promise is worse than a dropdown when the
 * theme turns out not to have the spot. The drawing shows the arrangement;
 * the check after publishing confirms the spot exists. Neither is enough on
 * its own.
 *
 * @param {string} type     Gallery type.
 * @param {string} target   Which pages.
 * @param {string} position Where on the page.
 * @return {Element} The drawing.
 */
function art( type, target, position ) {
	const page = el( 'span', { class: 'ocs-pv' } );
	const line = ( w ) => el( 'span', { class: 'ocs-pv__line', style: 'width:' + w + '%' } );

	// The gallery itself, drawn as what it is.
	const gallery = el( 'span', {
		class: 'ocs-pv__gal ocs-pv__gal--' + type + ( 'custom' === position ? ' ocs-pv__gal--loose' : '' ),
	} );
	const pieces = 'story' === type ? 4 : 3;

	for ( let i = 0; i < pieces; i++ ) {
		gallery.append( el( 'span', { class: 'ocs-pv__piece' } ) );
	}

	if ( 'floating' === type ) {
		gallery.classList.add( 'ocs-pv__gal--' + ( 'side_start' === position ? 'start' : 'end' ) );
		gallery.replaceChildren( el( 'span', { class: 'ocs-pv__piece' } ) );
	}

	page.append( el( 'span', { class: 'ocs-pv__header' } ) );

	// A floating video has no place in the flow of the document — it sits on
	// top of whatever is there. So the page is drawn plain and the gallery is
	// pinned to its corner.
	if ( 'floating' === type ) {
		page.append( line( 90 ), line( 70 ), line( 80 ), line( 45 ), gallery );

		return page;
	}

	const at = ( where ) => ( position === where ? gallery : null );

	// Node.append() turns a null into the string "null" — a spot that is not
	// this spot has to be dropped, not passed.
	const stack = ( ...parts ) => page.append( ...parts.filter( Boolean ) );

	if ( 'product' === target ) {
		const media = el( 'span', { class: 'ocs-pv__media' } );
		const side = el( 'span', { class: 'ocs-pv__side' }, [
			line( 80 ),
			line( 40 ),
			at( 'before_cart' ),
			el( 'span', { class: 'ocs-pv__cta' } ),
			at( 'after_cart' ),
		].filter( Boolean ) );

		stack(
			el( 'span', { class: 'ocs-pv__cols' }, [ media, side ] ),
			at( 'after_summary' ),
			line( 90 ),
			line( 60 ),
			at( 'end_of_content' ),
			at( 'custom' )
		);

		return page;
	}

	if ( 'category' === target ) {
		const grid = el( 'span', { class: 'ocs-pv__grid' } );

		for ( let i = 0; i < 6; i++ ) {
			grid.append( el( 'span', { class: 'ocs-pv__cell' } ) );
		}

		stack(
			line( 50 ),
			line( 85 ),
			at( 'above_products' ),
			grid,
			at( 'below_products' ),
			at( 'custom' )
		);

		return page;
	}

	// Home, a single page, the whole shop: one column of content.
	stack(
		at( 'above_content' ),
		el( 'span', { class: 'ocs-pv__hero' } ),
		line( 90 ),
		line( 70 ),
		line( 80 ),
		at( 'end_of_content' ),
		at( 'custom' )
	);

	return page;
}

/**
 * The wall: the same cards, wrapped instead of scrolling.
 *
 * @return {Element} The drawing.
 */
function wallArt() {
	const page = el( 'span', { class: 'ocs-pv' } );
	const grid = el( 'span', { class: 'ocs-pv__gal ocs-pv__gal--wall' } );

	for ( let i = 0; i < 6; i++ ) {
		grid.append( el( 'span', { class: 'ocs-pv__piece' } ) );
	}

	page.append(
		el( 'span', { class: 'ocs-pv__header' } ),
		el( 'span', { class: 'ocs-pv__line', style: 'width:60%' } ),
		grid
	);

	return page;
}

/* ---------------------------------------------------------------- state */

const state = {
	view: 'list',
	galleries: [],
	stories: [],
	draft: null,
	step: 1,
	busy: false,
	note: null,
	results: [],
};

const root = document.getElementById( 'ocs-wizard' );

function setState( patch ) {
	Object.assign( state, patch );
	render();
}

/**
 * Turn a saved gallery back into something the wizard can walk through.
 *
 * Three things have to be recovered, and each of them was silently wrong
 * before this existed. The kind of gallery is not stored — it is a property
 * of the surface — so without deriving it, step one opened blank and step
 * two had no positions to offer. And "which videos" has three stored
 * readings, only one of which is a list: a gallery set to "all galleries" or
 * to a collection would have shown an empty step three and then, on save,
 * been rewritten to a list of nothing.
 *
 * @param {Object} saved A placement from the server.
 * @return {Object} A draft.
 */
function reopen( saved ) {
	const type = ( cfg.types || [] ).find( ( x ) => ( x.surfaces || [] ).includes( saved.surface ) );
	const mode = ( saved.stories && saved.stories.mode ) || 'selected';

	let ids = ( saved.stories && saved.stories.ids ) || [];

	if ( 'all' === mode ) {
		ids = state.stories.map( ( story ) => story.id );
	} else if ( 'collection' === mode && saved.stories.collection ) {
		ids = state.stories
			.filter( ( story ) => story.collection === saved.stories.collection )
			.map( ( story ) => story.id );
	}

	return {
		...saved,
		existing: true,
		type: type ? type.id : 'cards',
		where: {
			ids: ( saved.where && saved.where.ids ) || [],
			exclude: ( saved.where && saved.where.exclude ) || [],
		},
		stories: { mode, ids, collection: ( saved.stories && saved.stories.collection ) || '' },
	};
}

function blank() {
	return {
		id: '',
		label: '',
		enabled: false,
		surface: '',
		type: '',
		target: '',
		position: '',
		where: { ids: [], exclude: [] },
		stories: { mode: 'selected', ids: [], collection: '' },
		chosen: [],
	};
}

/* ----------------------------------------------------------------- load */

async function load() {
	try {
		const [ placements, stories ] = await Promise.all( [
			api( '/admin/placements' ),
			api( '/admin/stories' ),
		] );

		setState( {
			galleries: placements.placements || [],
			stories: stories || [],
		} );
	} catch ( error ) {
		setState( { note: { kind: 'error', text: error.message } } );
	}
}

/**
 * Save the whole set, because that is the shape the endpoint takes.
 *
 * @param {Array} galleries Galleries to store.
 */
async function persist( galleries ) {
	const payload = galleries.map( ( g ) => ( {
		id: g.id,
		label: g.label,
		enabled: !! g.enabled,
		surface: g.surface,
		target: g.target,
		position: g.position,
		// Carried through untouched. A widget made before the wizard has a
		// raw hook and no position, and dropping these would silently move it
		// to the default spot the first time anyone opened it.
		hook: g.hook,
		priority: g.priority,
		where: g.where,
		stories: g.stories,
		desktop: g.desktop,
		mobile: g.mobile,
	} ) );

	const saved = await api( '/admin/placements', {
		method: 'POST',
		body: JSON.stringify( { placements: payload } ),
	} );

	return saved.placements || [];
}

/* ---------------------------------------------------------------- steps */

function stepper() {
	const steps = [ t.step1, t.step2, t.step3 ];

	return el(
		'ol',
		{ class: 'ocs-steps' },
		steps.map( ( label, i ) =>
			el( 'li', {
				class: 'ocs-steps__item',
				'aria-current': state.step === i + 1 ? 'step' : false,
				'data-done': state.step > i + 1 ? '' : false,
			}, [
				el( 'span', { class: 'ocs-steps__n', text: String( i + 1 ) } ),
				el( 'span', { text: label } ),
			] )
		)
	);
}

/**
 * A row of choices, each shown rather than described.
 *
 * @param {Array}    options  { id, label, note, art }.
 * @param {string}   current  Chosen id.
 * @param {Function} onPick   Called with the id.
 * @return {Element} The row.
 */
function tiles( options, current, onPick, locked ) {
	return el(
		'div',
		{ class: 'ocs-tiles ocs-tiles--big' + ( locked ? ' is-locked' : '' ) },
		options.map( ( option ) =>
			el( 'button', {
				class: 'ocs-tile',
				type: 'button',
				'aria-pressed': current === option.id ? 'true' : 'false',
				disabled: !! locked,
				onClick: () => onPick( option.id ),
			}, [
				el( 'span', { class: 'ocs-tile__art' }, [ option.art ] ),
				el( 'span', { class: 'ocs-tile__label', text: option.label } ),
				option.note ? el( 'span', { class: 'ocs-tile__note', text: option.note } ) : null,
			] )
		)
	);
}

/** Step one: what is it called, and what kind of gallery is it. */
function stepType() {
	const draft = state.draft;

	const options = ( cfg.types || [] ).map( ( type ) => ( {
		id: type.id,
		label: type.label,
		note: type.note,
		art: art( type.id, 'home', 'story' === type.id ? 'above_content' : 'end_of_content' ),
	} ) );

	return el( 'div', { class: 'ocs-wz__body' }, [
		el( 'label', { class: 'ocs-field' }, [
			el( 'span', { class: 'ocs-field__label', text: t.name } ),
			el( 'input', {
				class: 'ocs-input',
				type: 'text',
				value: draft.label,
				placeholder: t.namePlaceholder,
				// Typing never re-renders: the input would be replaced under
				// the cursor and the first letter would be the last one.
				onInput: ( e ) => {
					draft.label = e.target.value;
					paintNext();
				},
			} ),
			el( 'span', { class: 'ocs-field__note', text: t.nameNote } ),
		] ),
		el( 'h2', { class: 'ocs-wz__q', text: t.whichType } ),
		// The kind is settled once a gallery exists. A story holding five
		// slides cannot become a slider that shows one video a card without
		// somebody deciding what happens to the other four — so the way to
		// change it is Duplicate, which asks from the beginning and leaves the
		// original alone.
		tiles( options, draft.type, ( id ) => {
			draft.type = id;
			draft.surface = ( cfg.types.find( ( x ) => x.id === id ) || {} ).surfaces[ 0 ];
			// The spot belongs to the branch, so changing the branch throws
			// away a spot that may not exist in the new one.
			draft.position = '';
			setState( {} );
		}, draft.existing ),
		draft.existing ? el( 'p', { class: 'ocs-field__note', text: t.typeLocked } ) : null,
	] );
}

/** Step two: which pages, then where on them, then which ones exactly. */
function stepWhere() {
	const draft = state.draft;
	const parts = [
		el( 'h2', { class: 'ocs-wz__q', text: t.whichPages } ),
		tiles(
			( cfg.targets || [] ).map( ( target ) => {
				// Draw each kind of page with the gallery in the first spot
				// that page actually offers. Drawing them all in one fixed
				// spot leaves half the tiles showing a page with no gallery
				// on it, which reads as "nothing happens here".
				const first = ( ( cfg.positions[ draft.type ] || {} )[ target.id ] || [] )[ 0 ];

				return {
					id: target.id,
					label: target.label,
					art: art( draft.type, target.id, first ? first.id : 'custom' ),
				};
			} ),
			draft.target,
			( id ) => {
				draft.target = id;
				draft.position = '';
				draft.where.ids = [];
				setState( { results: [], pickingProducts: false } );
			}
		),
	];

	if ( ! draft.target ) {
		return el( 'div', { class: 'ocs-wz__body' }, parts );
	}

	const spots = ( cfg.positions[ draft.type ] || {} )[ draft.target ] || [];

	parts.push(
		el( 'h2', { class: 'ocs-wz__q', text: t.wherePage } ),
		tiles(
			spots.map( ( spot ) => ( {
				id: spot.id,
				label: spot.label,
				note: spot.theme ? t.themeWarning : '',
				art: art( draft.type, draft.target, spot.id ),
			} ) ),
			draft.position,
			( id ) => {
				draft.position = id;
				setState( {} );
			}
		)
	);

	const chosen = spots.find( ( s ) => s.id === draft.position );

	if ( chosen && 'custom' === chosen.id ) {
		parts.push( embedNote() );
	}

	if ( 'product' === draft.target ) {
		parts.push( whichThings( 'product' ) );
	}

	if ( 'category' === draft.target ) {
		parts.push( whichThings( 'category' ) );
	}

	if ( 'page' === draft.target ) {
		parts.push( whichThings( 'page' ) );
	}

	// A slider and a wall are the same gallery seen two ways, so this is a
	// display choice rather than a different kind of thing to make.
	if ( 'cards' === draft.type ) {
		parts.push(
			el( 'h2', { class: 'ocs-wz__q', text: t.rowOrWall } ),
			tiles(
				[
					{ id: 'slider', label: t.asSlider, art: art( 'cards', 'home', 'end_of_content' ) },
					{ id: 'grid', label: t.asGrid, art: wallArt() },
				],
				draft.surface,
				( id ) => {
					draft.surface = id;
					setState( {} );
				}
			)
		);
	}

	return el( 'div', { class: 'ocs-wz__body' }, parts );
}

/**
 * How to put it somewhere by hand.
 *
 * @return {Element} The note.
 */
function embedNote() {
	const code = '[oc_story id="' + ( state.draft.id || t.afterSaving ) + '"]';

	return el( 'div', { class: 'ocs-callout' }, [
		el( 'h3', { text: t.embedTitle } ),
		el( 'p', { text: t.embedHow } ),
		el( 'code', { class: 'ocs-code', text: code } ),
		el( 'p', { class: 'ocs-field__note', text: t.embedBuilder } ),
	] );
}

/**
 * Which products, categories or pages exactly.
 *
 * On product pages there is a second reading with no list at all: let the
 * videos decide. It is the option most shops want and the one hardest to
 * name, so it is spelled out rather than labelled.
 *
 * @param {string} kind 'product' | 'category' | 'page'.
 * @return {Element} The field.
 */
function whichThings( kind ) {
	const draft = state.draft;
	const label = { product: t.whichProduct, category: t.whichCategory, page: t.whichPage }[ kind ];

	const parts = [ el( 'h2', { class: 'ocs-wz__q', text: label } ) ];

	if ( 'product' === kind ) {
		const auto = ! draft.where.ids.length;

		parts.push(
			el( 'div', { class: 'ocs-choices' }, [
				el( 'label', { class: 'ocs-choice' }, [
					el( 'input', {
						type: 'radio',
						name: 'ocs-which',
						checked: auto,
						onChange: () => {
							draft.where.ids = [];
							setState( { results: [], pickingProducts: false } );
						},
					} ),
					el( 'span', {}, [
						el( 'b', { text: t.automatic } ),
						el( 'span', { class: 'ocs-field__note', text: t.automaticNote } ),
					] ),
				] ),
				el( 'label', { class: 'ocs-choice' }, [
					el( 'input', {
						type: 'radio',
						name: 'ocs-which',
						checked: ! auto,
						onChange: () => setState( { pickingProducts: true } ),
					} ),
					el( 'span', {}, [
						el( 'b', { text: t.namedProducts } ),
						el( 'span', { class: 'ocs-field__note', text: t.namedProductsNote } ),
					] ),
				] ),
			] )
		);

		if ( auto && ! state.pickingProducts ) {
			return el( 'div', {}, parts );
		}
	}

	parts.push( picker( kind ) );

	return el( 'div', {}, parts );
}

let searchTimer = null;
let paintResults = () => {};

/**
 * What the lookup endpoint calls this kind of thing.
 *
 * @param {string} kind Wizard's word.
 * @return {string} The endpoint's word.
 */
function lookupType( kind ) {
	return 'category' === kind ? 'term' : kind;
}

/**
 * Type a name, see the matches, pick as many as apply.
 *
 * @param {string} kind What to look for.
 * @return {Element} The picker.
 */
function picker( kind ) {
	const draft = state.draft;
	const results = el( 'div', { class: 'ocs-results' } );

	// The list is repainted on its own, never through setState: a full render
	// replaces the input under the cursor, which is how the search field once
	// ate every keystroke after the first.
	paintResults = () => {
		results.replaceChildren(
			...state.results.map( ( item ) =>
				el( 'button', {
					class: 'ocs-result',
					type: 'button',
					onClick: () => {
						if ( ! draft.where.ids.includes( item.id ) ) {
							draft.where.ids.push( item.id );
						}
						setState( { results: [] } );
					},
				}, [
					item.thumb ? el( 'img', { src: item.thumb, alt: '', loading: 'lazy' } ) : null,
					el( 'span', { text: item.name } ),
				] )
			)
		);
	};

	const chips = el(
		'div',
		{ class: 'ocs-chips' },
		draft.where.ids.map( ( id ) => {
			const known = ( state.names || {} )[ kind + ':' + id ];

			return el( 'span', { class: 'ocs-chip' }, [
				el( 'span', { text: known || '#' + id } ),
				el( 'button', {
					class: 'ocs-chip__x',
					type: 'button',
					'aria-label': t.remove,
					text: '×',
					onClick: () => {
						draft.where.ids = draft.where.ids.filter( ( x ) => x !== id );
						setState( {} );
					},
				} ),
			] );
		} )
	);

	const input = el( 'input', {
		class: 'ocs-input',
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
					const found = await api(
						'/admin/lookup?type=' + lookupType( kind ) + '&search=' + encodeURIComponent( term )
					);

					state.names = state.names || {};
					found.forEach( ( item ) => {
						state.names[ kind + ':' + item.id ] = item.name;
					} );

					state.results = found;
					paintResults();
				} catch ( error ) {
					state.results = [];
					paintResults();
				}
			}, 250 );
		},
	} );

	return el( 'div', { class: 'ocs-picker' }, [ chips, input, results ] );
}

/** Step three: the videos themselves. */
function stepContent() {
	const draft = state.draft;
	const chosen = draft.stories.ids
		.map( ( id ) => state.stories.find( ( s ) => s.id === id ) )
		.filter( Boolean );

	// A floating gallery is one clip in a corner, so its step three holds one
	// item. A story gallery holds several stories; a slider holds several
	// videos, one to a card.
	const single = 'floating' === draft.type;
	const addLabel = 'story' === draft.type ? t.addStory : t.addVideo;

	const cards = chosen.map( ( story ) =>
		el( 'div', { class: 'ocs-piece', 'data-type': draft.type }, [
			el( 'button', {
				class: 'ocs-piece__open',
				type: 'button',
				onClick: () => openEditor( story.id ),
			}, [
				story.poster
					? el( 'img', { src: story.poster, alt: '' } )
					: el( 'span', { class: 'ocs-piece__blank' } ),
			] ),
			el( 'span', { class: 'ocs-piece__name', text: story.title || t.untitled } ),
			el( 'button', {
				class: 'ocs-piece__x',
				type: 'button',
				'aria-label': t.remove,
				text: '×',
				onClick: () => {
					draft.stories.ids = draft.stories.ids.filter( ( x ) => x !== story.id );
					setState( {} );
				},
			} ),
		] )
	);

	if ( ! single || ! chosen.length ) {
		cards.push(
			el( 'button', {
				class: 'ocs-piece ocs-piece--add',
				type: 'button',
				onClick: () => openEditor( null ),
			}, [
				el( 'span', { class: 'ocs-piece__plus', text: '+' } ),
				el( 'span', { class: 'ocs-piece__name', text: addLabel } ),
			] )
		);
	}

	return el( 'div', { class: 'ocs-wz__body' }, [
		el( 'h2', { class: 'ocs-wz__q', text: 'story' === draft.type ? t.theStories : t.theVideos } ),
		el( 'p', { class: 'ocs-field__note', text: single ? t.floatingNote : '' } ),
		el( 'div', { class: 'ocs-pieces', 'data-type': draft.type }, cards ),
	] );
}

/**
 * Hand over to the video editor, and come back with what it made.
 *
 * @param {number|null} id Existing series, or null for a new one.
 */
async function openEditor( id ) {
	const studio = await import( new URL( cfg.studio, document.baseURI ).href );

	studio.mount( root, {
		story: id,
		single: 'floating' !== state.draft.type && 'story' !== state.draft.type,
		onDone: ( saved ) => {
			if ( saved && ! state.draft.stories.ids.includes( saved.id ) ) {
				state.draft.stories.ids.push( saved.id );
			}

			load().then( render );
		},
	} );
}

/* ----------------------------------------------------------------- shell */

let paintNext = () => {};

function wizard() {
	const draft = state.draft;

	const ready = {
		1: () => draft.label.trim().length > 0 && draft.type,
		// A category gallery with no categories named matches no page at all,
		// and a specific-page gallery with no page is the same. Both used to
		// be publishable, and both rendered nowhere.
		2: () => draft.target && draft.position
			&& ( ! [ 'page', 'category' ].includes( draft.target ) || draft.where.ids.length ),
		3: () => draft.stories.ids.length > 0,
	};

	const next = el( 'button', {
		class: 'ocs-btn ocs-btn--primary',
		type: 'button',
		text: state.step < 3 ? t.next : t.publish,
		onClick: () => ( state.step < 3 ? setState( { step: state.step + 1 } ) : finish( true ) ),
	} );

	// Re-checked without a render, so typing a name lights the button up
	// without the field losing focus.
	paintNext = () => {
		next.disabled = ! ready[ state.step ]();
	};
	paintNext();

	const body = { 1: stepType, 2: stepWhere, 3: stepContent }[ state.step ]();

	return el( 'div', { class: 'ocs-wz' }, [
		el( 'div', { class: 'ocs-head' }, [
			el( 'button', {
				class: 'ocs-btn ocs-btn--link',
				type: 'button',
				text: t.allGalleries,
				onClick: () => setState( { view: 'list', draft: null, step: 1 } ),
			} ),
			el( 'h1', { text: draft.label.trim() || t.newGallery } ),
		] ),
		stepper(),
		// Anything that goes wrong while saving has to be said here, in the
		// wizard, rather than only on the list nobody is looking at yet.
		noteBar(),
		body,
		el( 'div', { class: 'ocs-wz__foot' }, [
			state.step > 1
				? el( 'button', {
					class: 'ocs-btn',
					type: 'button',
					text: t.back,
					onClick: () => setState( { step: state.step - 1 } ),
				} )
				: null,
			el( 'span', { class: 'ocs-wz__spacer' } ),
			3 === state.step
				? el( 'button', {
					class: 'ocs-btn',
					type: 'button',
					text: t.saveDraft,
					onClick: () => finish( false ),
				} )
				: null,
			next,
		] ),
	] );
}

/**
 * Save, and say plainly whether anyone can see it.
 *
 * @param {boolean} live Publish, or keep it a draft.
 */
async function finish( live ) {
	const draft = state.draft;

	draft.enabled = !! live;

	// A gallery aimed at product pages with no products named is the automatic
	// one, and automatic is a rule rather than a list: each product page shows
	// the videos that tag it. Storing a fixed list there would put the same
	// videos on every product, which is the opposite of what the screen said.
	draft.stories.mode = ( 'product' === draft.target && ! draft.where.ids.length ) ? 'tagged' : 'selected';

	setState( { busy: true } );

	try {
		// Publishing the gallery publishes its videos. Anything else is the
		// trap this plugin already fell into once: a gallery placed perfectly,
		// pointing at videos that are still drafts, showing nothing at all —
		// and no screen anywhere saying why.
		if ( live ) {
			await Promise.all(
				draft.stories.ids.map( ( id ) =>
					api( '/admin/stories/' + id, {
						method: 'POST',
						body: JSON.stringify( { status: 'publish' } ),
					} ).catch( () => null )
				)
			);
		}

		const others = state.galleries.filter( ( g ) => g.id !== draft.id );
		const saved = await persist( others.concat( [ draft ] ) );

		// The endpoint mints an id for a new gallery, so the one to go and
		// look for is whichever came back that the others did not have.
		const known = others.map( ( g ) => g.id );
		const mine = saved.find( ( g ) => ! known.includes( g.id ) ) || saved.find( ( g ) => g.id === draft.id );

		setState( {
			galleries: saved,
			view: 'list',
			draft: null,
			step: 1,
			busy: false,
			note: {
				kind: live ? 'ok' : 'info',
				text: live ? t.publishing : t.draftSaved,
			},
		} );

		if ( live && mine ) {
			verify( mine );
		}
	} catch ( error ) {
		setState( { busy: false, note: { kind: 'error', text: error.message } } );
	}
}

/**
 * Go and look at the shop, then say what is actually there.
 *
 * Publishing makes a person feel finished, and they go straight to the shop
 * to admire it. If the spot they picked does not exist in their theme, the
 * kindest moment to say so is now — not when they are staring at a page
 * wondering what they did wrong.
 *
 * @param {Object} gallery The saved gallery.
 */
async function verify( gallery ) {
	try {
		const outcome = await api( '/admin/placements/' + gallery.id + '/check', { method: 'POST' } );

		if ( 'skipped' === outcome.status ) {
			return;
		}

		const kind = { found: 'ok', missing: 'warn', unknown: 'info' }[ outcome.status ] || 'info';
		const text = {
			found: t.checkFound,
			missing: t.checkMissing,
			unknown: t.checkUnknown,
		}[ outcome.status ];

		setState( {
			note: {
				kind,
				text: outcome.reason ? text + ' ' + outcome.reason : text,
				// A fresh query string on the link, so what opens is the page
				// as it is now rather than a copy a cache made a minute ago.
				link: outcome.url ? outcome.url + ( outcome.url.includes( '?' ) ? '&' : '?' ) + 'ocs=' + Date.now() : '',
				linkText: t.viewOnShop,
			},
		} );
	} catch ( error ) {
		// A gallery that saved is published whether or not we could look at
		// it. Failing to check is not failing to publish, and must not read
		// like it.
		setState( { note: { kind: 'info', text: t.published } } );
	}
}

function listView() {
	const rows = state.galleries.map( ( g ) => {
		const type = ( cfg.types || [] ).find( ( x ) => ( x.surfaces || [] ).includes( g.surface ) );
		const target = ( cfg.targets || [] ).find( ( x ) => x.id === g.target );
		const count = 'tagged' === ( g.stories && g.stories.mode )
			? ( g.stories.ids || [] ).length
			: ( ( g.stories && g.stories.ids ) || [] ).length;

		return el( 'tr', {}, [
			el( 'td', {}, [
				el( 'button', {
					class: 'ocs-linkish',
					type: 'button',
					text: g.label || t.untitled,
					onClick: () => setState( { view: 'wizard', draft: reopen( g ), step: 1, note: null } ),
				} ),
			] ),
			el( 'td', { text: type ? type.label : g.surface } ),
			el( 'td', { text: target ? target.label : '' } ),
			el( 'td', { text: String( count ) } ),
			el( 'td', {}, [
				el( 'span', {
					class: 'ocs-pill ocs-pill--' + ( g.enabled ? 'on' : 'off' ),
					text: g.enabled ? t.live : t.draft,
				} ),
			] ),
			el( 'td', { class: 'ocs-table__acts' }, [
				// Duplicating is how a gallery changes its kind. Changing it in
				// place would leave a story's five slides inside a surface that
				// shows one video a card, so the copy asks the question again
				// from step one and the original is left alone.
				el( 'button', {
					class: 'ocs-btn ocs-btn--small',
					type: 'button',
					text: t.duplicate,
					onClick: () => {
						const copy = reopen( g );

						copy.id = '';
						copy.existing = false;
						copy.enabled = false;
						copy.label = ( g.label || t.untitled ) + t.copySuffix;

						setState( { view: 'wizard', draft: copy, step: 1, note: null } );
					},
				} ),
				el( 'button', {
					class: 'ocs-btn ocs-btn--small ocs-btn--danger',
					type: 'button',
					text: t.remove,
					onClick: () => removeGallery( g ),
				} ),
			] ),
		] );
	} );

	return el( 'div', {}, [
		el( 'div', { class: 'ocs-head' }, [
			el( 'h1', { text: t.title } ),
			el( 'button', {
				class: 'ocs-btn ocs-btn--primary',
				type: 'button',
				text: t.newGallery,
				onClick: () => setState( { view: 'wizard', draft: blank(), step: 1, note: null } ),
			} ),
		] ),
		noteBar(),
		state.galleries.length
			? el( 'table', { class: 'ocs-table' }, [
				el( 'thead', {}, [
					el( 'tr', {}, [
						el( 'th', { text: t.name } ),
						el( 'th', { text: t.type } ),
						el( 'th', { text: t.where } ),
						el( 'th', { text: t.videos } ),
						el( 'th', { text: t.status } ),
						el( 'th', {} ),
					] ),
				] ),
				el( 'tbody', {}, rows ),
			] )
			: el( 'div', { class: 'ocs-empty' }, [
				el( 'h2', { text: t.empty } ),
				el( 'p', { text: t.emptyHint } ),
			] ),
	] );
}

/**
 * Take a gallery off the shop.
 *
 * The videos are not touched — they are attachments and edits somebody made,
 * and a gallery is only a decision about where to show them.
 *
 * @param {Object} gallery The gallery to remove.
 */
async function removeGallery( gallery ) {
	// eslint-disable-next-line no-alert
	if ( ! window.confirm( t.removeSure.replace( '%s', gallery.label || t.untitled ) ) ) {
		return;
	}

	setState( { busy: true } );

	try {
		const saved = await persist( state.galleries.filter( ( g ) => g.id !== gallery.id ) );

		setState( { galleries: saved, busy: false, note: { kind: 'info', text: t.removed } } );
	} catch ( error ) {
		setState( { busy: false, note: { kind: 'error', text: error.message } } );
	}
}

function noteBar() {
	if ( ! state.note ) {
		return null;
	}

	return el( 'div', { class: 'ocs-note ocs-note--' + state.note.kind }, [
		el( 'span', { text: state.note.text } ),
		state.note.link
			? el( 'a', { href: state.note.link, target: '_blank', rel: 'noopener', text: state.note.linkText } )
			: null,
	] );
}

function render() {
	if ( ! root ) {
		return;
	}

	root.replaceChildren( 'wizard' === state.view ? wizard() : listView() );
	root.removeAttribute( 'data-loading' );
}

render();
load();
