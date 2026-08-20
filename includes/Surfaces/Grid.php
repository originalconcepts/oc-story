<?php
/**
 * The video wall.
 *
 * @package OC_Story
 */

namespace OCS\Surfaces;

defined( 'ABSPATH' ) || exit;

/**
 * The same cards as the slider, wrapping instead of scrolling — a wall of
 * video for a landing page or an influencers section. One template serves
 * both; the surface class flips the track from a row into a grid.
 */
class Grid extends Slider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'grid';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Video wall', 'oc-story' );
	}
}
