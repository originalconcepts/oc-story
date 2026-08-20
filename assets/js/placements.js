/**
 * The placements screen.
 *
 * The rules engine this drives has existed since the first milestone and is
 * covered by the PHP harness. What is here is only the part a shop owner
 * touches: choosing pages by name, choosing a position by what it does rather
 * than by its hook name, and setting the phone up differently from the desktop.
 */
( function () {
	'use strict';

	var cfg = window.ocsPlacements || {};
	var t = cfg.i18n || {};
	var root = document.getElementById( 'ocs-placements' );

	var state = {
		placements: [],
		surfaces: [],
		hooks: {},
		scopes: {},
		stories: null,
		names: {},
		results: {},
		busy: false,
		note: null,
		dirty: false,
	};

	/* ---------------------------------------------------------------- utils */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];

			if ( 'class' === key ) {
				node.className = value;
			} else if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 0 === key.indexOf( 'on' ) ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else if ( false !== value && null !== value && undefined !== value ) {
				node.setAttribute( key, value );
			}
		} );

		[].concat( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.append( child );
			}
		} );

		return node;
	}

	function api( path, init ) {
		init = init || {};

		return fetch( cfg.api.root.replace( /\/$/, '' ) + path, {
			credentials: 'same-origin',
			method: init.method || 'GET',
			body: init.body,
			headers: Object.assign(
				{ 'X-WP-Nonce': cfg.api.nonce },
				init.body ? { 'Content-Type': 'application/json' } : {}
			),
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( ( body && body.message ) || t.failed );
				}
				return body;
			} );
		} );
	}

	function set( patch ) {
		Object.assign( state, patch );
		render();
	}

	function fail( e ) {
		set( { busy: false, note: { kind: 'error', text: e.message || String( e ) } } );
	}

	/**
	 * Which thing a scope is choosing between.
	 *
	 * @param {string} scope Scope.
	 * @return {?Object} Lookup type and label.
	 */
	function picker( scope ) {
		if ( 'pages' === scope ) {
			return { type: 'page', label: t.choosePages };
		}
		if ( 'products' === scope ) {
			return { type: 'product', label: t.chooseProducts };
		}
		if ( 'terms' === scope ) {
			return { type: 'term', label: t.chooseTerms };
		}
		return null;
	}

	/* ----------------------------------------------------------------- data */

	function load() {
		api( '/admin/placements' ).then( function ( data ) {
			set( {
				placements: data.placements || [],
				surfaces: data.surfaces || [],
				hooks: data.hooks || {},
				scopes: data.scopes || {},
			} );

			resolveNames();
		} ).catch( fail );
	}

	/**
	 * Turn the saved IDs back into names, so a placement reads as "Home, About"
	 * rather than as a list of numbers nobody recognises.
	 */
	function resolveNames() {
		var wanted = { page: [], product: [], term: [] };

		state.placements.forEach( function ( placement ) {
			var kind = picker( placement.where.scope );

			if ( kind ) {
				wanted[ kind.type ] = wanted[ kind.type ].concat( placement.where.ids || [] );
			}
		} );

		Object.keys( wanted ).forEach( function ( type ) {
			var ids = wanted[ type ].filter( function ( id, i, all ) {
				return all.indexOf( id ) === i;
			} );

			if ( ! ids.length ) {
				return;
			}

			api( '/admin/lookup?type=' + type + '&ids=' + ids.join( ',' ) ).then( function ( rows ) {
				rows.forEach( function ( row ) {
					state.names[ type + ':' + row.id ] = row.name;
				} );
				render();
			} ).catch( function () {} );
		} );
	}

	function stories() {
		if ( state.stories ) {
			return;
		}

		state.stories = [];

		api( '/admin/stories' ).then( function ( rows ) {
			state.stories = rows.map( function ( row ) {
				return { id: row.id, title: row.title };
			} );
			render();
		} ).catch( function () {} );
	}

	var timers = {};

	// One painter per lookup field, re-registered on every render. Typing must
	// only ever redraw the results list: a full render replaces the input
	// mid-word and throws the keyboard focus out after the first letter.
	var resultPainters = {};

	function paintResults( key ) {
		if ( resultPainters[ key ] ) {
			resultPainters[ key ]();
		}
	}

	function search( key, type, term ) {
		clearTimeout( timers[ key ] );

		if ( term.trim().length < 2 ) {
			state.results[ key ] = [];
			paintResults( key );
			return;
		}

		timers[ key ] = setTimeout( function () {
			api( '/admin/lookup?type=' + type + '&search=' + encodeURIComponent( term ) ).then( function ( rows ) {
				state.results[ key ] = rows;
				rows.forEach( function ( row ) {
					state.names[ type + ':' + row.id ] = row.name;
				} );
				paintResults( key );
			} ).catch( function () {} );
		}, 250 );
	}

	function save() {
		set( { busy: true, note: null } );

		api( '/admin/placements', {
			method: 'POST',
			body: JSON.stringify( { placements: state.placements } ),
		} ).then( function ( data ) {
			set( {
				placements: data.placements || [],
				busy: false,
				dirty: false,
				note: { kind: 'info', text: t.saved },
			} );
		} ).catch( fail );
	}

	function add() {
		state.placements.push( {
			id: '',
			label: '',
			enabled: true,
			surface: state.surfaces.length ? state.surfaces[ 0 ].id : 'circles',
			where: { scope: 'home', ids: [], exclude: [] },
			hook: 'auto',
			priority: 15,
			stories: { mode: 'all', ids: [] },
			desktop: { show: true, size: 84, labels: true, align: 'start', max: 12 },
			mobile: { show: true, size: 64, labels: true, align: 'start', max: 20 },
		} );

		set( { dirty: true } );
	}

	/* --------------------------------------------------------------- fields */

	function field( label, control, hint ) {
		return el( 'div', { class: 'ocs-field' }, [
			el( 'label', { text: label } ),
			control,
			hint ? el( 'p', { class: 'ocs-hint', text: hint } ) : null,
		] );
	}

	function select( value, options, onChange ) {
		var node = el( 'select', { class: 'ocs-input', onChange: onChange } );

		Object.keys( options ).forEach( function ( key ) {
			var option = el( 'option', { value: key, text: options[ key ] } );

			if ( String( key ) === String( value ) ) {
				option.selected = true;
			}

			node.append( option );
		} );

		return node;
	}

	function toggle( label, checked, onChange ) {
		var input = el( 'input', { type: 'checkbox', onChange: onChange } );
		input.checked = !! checked;

		return el( 'label', { class: 'ocs-check' }, [ input, el( 'span', { text: label } ) ] );
	}

	function chips( key, type, ids, onRemove ) {
		return el( 'div', { class: 'ocs-chips' }, ( ids || [] ).map( function ( id ) {
			return el( 'span', { class: 'ocs-chip' }, [
				el( 'span', { text: state.names[ type + ':' + id ] || '#' + id } ),
				el( 'button', {
					class: 'ocs-btn ocs-btn--ghost',
					type: 'button',
					text: '×',
					'aria-label': t.remove,
					onClick: function () {
						onRemove( id );
					},
				} ),
			] );
		} ) );
	}

	function lookupField( key, kind, ids, onAdd, onRemove ) {
		var list = el( 'ul', { class: 'ocs-results' } );

		var build = function () {
			var rows = ( state.results[ key ] || [] ).map( function ( row ) {
				return el( 'li', {}, [
					el( 'button', {
						class: 'ocs-result',
						type: 'button',
						onClick: function () {
							onAdd( row.id );
						},
					}, [ el( 'span', { class: 'ocs-result__name', text: row.name } ) ] ),
				] );
			} );

			list.replaceChildren.apply( list, rows );
		};

		build();
		resultPainters[ key ] = build;

		return field(
			kind.label,
			el( 'div', {}, [
				chips( key, kind.type, ids, onRemove ),
				el( 'div', { class: 'ocs-search' }, [
					el( 'input', {
						class: 'ocs-input',
						type: 'search',
						placeholder: t.search,
						onInput: function ( e ) {
							search( key, kind.type, e.target.value );
						},
					} ),
					list,
				] ),
			] )
		);
	}

	function deviceBlock( placement, device, label ) {
		var conf = placement[ device ];

		return el( 'div', { class: 'ocs-device' }, [
			el( 'strong', { text: label } ),
			toggle( t.show, conf.show, function ( e ) {
				conf.show = e.target.checked;
				set( { dirty: true } );
			} ),
			field(
				t.size,
				el( 'input', {
					class: 'ocs-input',
					type: 'number',
					min: '40',
					max: '160',
					value: conf.size,
					onChange: function ( e ) {
						conf.size = parseInt( e.target.value, 10 ) || 64;
						state.dirty = true;
					},
				} )
			),
			field(
				t.max,
				el( 'input', {
					class: 'ocs-input',
					type: 'number',
					min: '1',
					max: '50',
					value: conf.max,
					onChange: function ( e ) {
						conf.max = parseInt( e.target.value, 10 ) || 12;
						state.dirty = true;
					},
				} )
			),
			field(
				t.align,
				select(
					conf.align,
					{ start: t.alignStart, center: t.alignCenter, end: t.alignEnd },
					function ( e ) {
						conf.align = e.target.value;
						set( { dirty: true } );
					}
				)
			),
			toggle( t.labels, conf.labels, function ( e ) {
				conf.labels = e.target.checked;
				set( { dirty: true } );
			} ),
		] );
	}

	/* --------------------------------------------------------------- render */

	function card( placement, index ) {
		var key = placement.id || 'new-' + index;
		var kind = picker( placement.where.scope );

		var surfaces = {};
		state.surfaces.forEach( function ( surface ) {
			surfaces[ surface.id ] = surface.label;
		} );

		var modes = { all: t.modeAll, selected: t.modeSelected };
		if ( 'tagged' === placement.where.scope ) {
			modes.tagged = t.modeTagged;
		}

		if ( 'selected' === placement.stories.mode ) {
			stories();
		}

		return el( 'div', { class: 'ocs-editor', style: 'margin-block-end:16px' }, [
			el( 'div', { class: 'ocs-editor__head' }, [
				el( 'strong', { text: placement.label || t.namePlaceholder } ),
				toggle( t.enabled, placement.enabled, function ( e ) {
					placement.enabled = e.target.checked;
					set( { dirty: true } );
				} ),
				el( 'button', {
					class: 'ocs-btn ocs-btn--danger',
					type: 'button',
					text: t.remove,
					onClick: function () {
						state.placements.splice( index, 1 );
						set( { dirty: true } );
					},
				} ),
			] ),
			el( 'div', { class: 'ocs-editor__body ocs-editor__body--wide' }, [
				el( 'div', {}, [
					field(
						t.name,
						el( 'input', {
							class: 'ocs-input',
							type: 'text',
							value: placement.label,
							placeholder: t.namePlaceholder,
							onInput: function ( e ) {
								placement.label = e.target.value;
								state.dirty = true;
							},
						} )
					),
					field( t.surface, select( placement.surface, surfaces, function ( e ) {
						placement.surface = e.target.value;
						set( { dirty: true } );
					} ) ),
					field( t.scope, select( placement.where.scope, state.scopes, function ( e ) {
						placement.where.scope = e.target.value;
						placement.where.ids = [];
						set( { dirty: true } );
					} ) ),
					kind
						? lookupField(
							key + ':where',
							kind,
							placement.where.ids,
							function ( id ) {
								if ( placement.where.ids.indexOf( id ) < 0 ) {
									placement.where.ids.push( id );
								}
								state.results[ key + ':where' ] = [];
								set( { dirty: true } );
							},
							function ( id ) {
								placement.where.ids = placement.where.ids.filter( function ( existing ) {
									return existing !== id;
								} );
								set( { dirty: true } );
							}
						)
						: null,
					field(
						t.position,
						select( placement.hook, state.hooks, function ( e ) {
							placement.hook = e.target.value;
							set( { dirty: true } );
						} ),
						'manual' === placement.hook ? t.manualHint : ''
					),
					field( t.whichStories, select( placement.stories.mode, modes, function ( e ) {
						placement.stories.mode = e.target.value;
						set( { dirty: true } );
					} ) ),
					'selected' === placement.stories.mode
						? el( 'div', { class: 'ocs-chips' }, ( state.stories || [] ).map( function ( story ) {
							var on = placement.stories.ids.indexOf( story.id ) > -1;

							return el( 'button', {
								class: 'ocs-chip' + ( on ? ' ocs-chip--on' : '' ),
								type: 'button',
								text: story.title || '#' + story.id,
								onClick: function () {
									placement.stories.ids = on
										? placement.stories.ids.filter( function ( id ) {
											return id !== story.id;
										} )
										: placement.stories.ids.concat( [ story.id ] );
									set( { dirty: true } );
								},
							} );
						} ) )
						: null,
				] ),
				el( 'div', { class: 'ocs-devices' }, [
					deviceBlock( placement, 'desktop', t.desktop ),
					deviceBlock( placement, 'mobile', t.mobile ),
				] ),
			] ),
		] );
	}

	function render() {
		var children = [
			el( 'div', { class: 'ocs-head' }, [
				el( 'h1', { text: t.title } ),
				el( 'div', { class: 'ocs-actions' }, [
					el( 'button', { class: 'ocs-btn', type: 'button', text: t.add, onClick: add } ),
					el( 'button', {
						class: 'ocs-btn ocs-btn--primary',
						type: 'button',
						disabled: state.busy,
						text: state.busy ? t.saving : ( state.dirty ? t.save : t.saved ),
						onClick: save,
					} ),
				] ),
			] ),
		];

		if ( state.note ) {
			children.push( el( 'div', {
				class: 'ocs-note' + ( 'error' === state.note.kind ? ' ocs-note--error' : '' ),
				text: state.note.text,
			} ) );
		}

		if ( state.placements.length ) {
			state.placements.forEach( function ( placement, index ) {
				children.push( card( placement, index ) );
			} );
		} else {
			children.push( el( 'div', { class: 'ocs-empty' }, [
				el( 'h2', { text: t.empty } ),
				el( 'p', { text: t.emptyHint } ),
			] ) );
		}

		root.replaceChildren.apply( root, children );
		root.removeAttribute( 'data-loading' );
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( state.dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	render();
	load();
}() );
