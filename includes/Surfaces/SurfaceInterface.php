<?php
/**
 * Display surfaces.
 *
 * @package OC_Story
 */

namespace OCS\Surfaces;

defined( 'ABSPATH' ) || exit;

/**
 * A surface is one way of showing the same stories.
 *
 * The circles bar, the slider and the product-page block are not three
 * features — they are three renderers over one library and one player. Adding a
 * fourth means implementing this and nothing else.
 */
interface SurfaceInterface {

	/**
	 * Machine id, stored on a placement.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human label for the placements screen.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Can this surface appear in this context?
	 *
	 * @param array $context Request context, as built by Display\Injector.
	 * @return bool
	 */
	public function supports( array $context );

	/**
	 * Render the markup.
	 *
	 * @param array $stories   Stories, already shaped by Model\Story::to_array().
	 * @param array $placement The placement being rendered.
	 * @return string
	 */
	public function render( array $stories, array $placement );
}
