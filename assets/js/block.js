/**
 * The OC Story block.
 *
 * Written against the global `wp` objects with no JSX and no build step, which
 * is the whole reason this plugin has no `node_modules`. It is a placeholder in
 * the editor and a server-rendered bar on the front end.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var e = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'oc-story/stories', {
		apiVersion: 2,
		title: __( 'OC Story', 'oc-story' ),
		description: __( 'A row of story circles.', 'oc-story' ),
		icon: 'format-video',
		category: 'media',
		attributes: {
			placement: { type: 'string', default: '' },
			ids: { type: 'string', default: '' },
			size: { type: 'number', default: 0 },
			labels: { type: 'boolean', default: true },
		},

		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;

			return e(
				'div',
				blockEditor.useBlockProps(),
				e(
					blockEditor.InspectorControls,
					{},
					e(
						components.PanelBody,
						{ title: __( 'Story circles', 'oc-story' ) },
						e( components.TextControl, {
							label: __( 'Only these stories (IDs, comma separated)', 'oc-story' ),
							help: __( 'Leave empty to show them all.', 'oc-story' ),
							value: a.ids,
							onChange: function ( value ) {
								set( { ids: value.replace( /[^0-9,]/g, '' ) } );
							},
						} ),
						e( components.RangeControl, {
							label: __( 'Circle size', 'oc-story' ),
							value: a.size || 84,
							min: 40,
							max: 160,
							onChange: function ( value ) {
								set( { size: value } );
							},
						} ),
						e( components.ToggleControl, {
							label: __( 'Caption under the circle', 'oc-story' ),
							checked: a.labels,
							onChange: function ( value ) {
								set( { labels: value } );
							},
						} )
					)
				),
				e(
					components.Placeholder,
					{
						icon: 'format-video',
						label: __( 'OC Story', 'oc-story' ),
					},
					// Deliberately not a live preview. Rendering the real bar
					// here would load video into the editor to show something
					// that is going to change before anyone reads it.
					a.ids
						? __( 'Showing the stories you chose.', 'oc-story' )
						: __( 'Showing every published story.', 'oc-story' )
				)
			);
		},

		save: function () {
			return null;
		},
	} );
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
) );
