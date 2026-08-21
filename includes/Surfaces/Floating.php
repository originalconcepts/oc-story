<?php
/**
 * The floating video.
 *
 * @package OC_Story
 */

namespace OCS\Surfaces;

defined( 'ABSPATH' ) || exit;

/**
 * A small video in the corner of the page, playing itself, silent.
 *
 * The one surface that costs bytes before anybody asks for it: unlike the
 * circles, this thing is a playing video the moment the page settles. So it
 * plays one clip and one only, it waits until the page has finished loading
 * everything else, and it can be dismissed — and once dismissed it stays gone
 * for a week. A corner video that comes back on every page is the reason
 * people install ad blockers.
 */
class Floating extends AbstractSurface {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'floating';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Floating video', 'oc-story' );
	}

	/**
	 * Anywhere — it belongs to the viewport, not to the document.
	 *
	 * @param array $context Request context.
	 * @return bool
	 */
	public function supports( array $context ) {
		return true;
	}

	/**
	 * Render the corner.
	 *
	 * @param array $stories   Stories.
	 * @param array $placement Placement.
	 * @return string
	 */
	public function render( array $stories, array $placement ) {
		$visible = $this->playable( $stories );

		if ( ! $visible ) {
			return '';
		}

		// Two floating videos on one page is two things shouting at once. The
		// first one to render wins and the rest stand down.
		if ( self::$placed ) {
			return '';
		}

		self::$placed = true;

		// One clip. The payload still carries the whole gallery, because
		// tapping opens the player and the player can page through the rest.
		$first = $visible[0];
		$slide = isset( $first['slides'][0] ) ? $first['slides'][0] : array();

		$inline = $this->payload_tag( $visible, $placement['id'] );

		return $this->template(
			'floating.php',
			array(
				'stories'   => $visible,
				'story'     => $first,
				'slide'     => $slide,
				'placement' => $placement,
				'inline'    => $inline,
				'src'       => '' === $inline ? $this->payload_src( $visible ) : '',
				'style'     => $this->corner_vars( $placement ),
				'side'      => 'side_start' === $placement['position'] ? 'start' : 'end',
				'surface'   => $this,
			)
		);
	}

	/**
	 * Size and distance from the bottom, per device.
	 *
	 * A default of 24 on a desktop and 86 on a phone: the second number
	 * clears a sticky add-to-cart bar, which lives in exactly this corner and
	 * is the one control in the shop that must never be covered.
	 *
	 * @param array $placement Placement.
	 * @return string
	 */
	protected function corner_vars( array $placement ) {
		$desktop = (int) $placement['desktop']['size'];
		$mobile  = (int) $placement['mobile']['size'];

		return sprintf(
			'--ocs-size:%dpx;--ocs-size-mobile:%dpx;--ocs-float-bottom:%dpx;--ocs-float-bottom-mobile:%dpx',
			$desktop < 100 ? (int) round( $desktop * 1.6 ) : $desktop,
			$mobile < 100 ? (int) round( $mobile * 1.6 ) : $mobile,
			(int) $placement['desktop']['offset'],
			(int) $placement['mobile']['offset']
		);
	}

	/**
	 * Whether a corner has already been claimed on this request.
	 *
	 * @var bool
	 */
	protected static $placed = false;
}
