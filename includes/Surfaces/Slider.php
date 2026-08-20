<?php
/**
 * The video slider.
 *
 * @package OC_Story
 */

namespace OCS\Surfaces;

defined( 'ABSPATH' ) || exit;

/**
 * A scrollable row of tall video cards — the shape UGC takes on a shop's home
 * page or a landing page, where a row of small circles would be too quiet.
 *
 * Same library, same player, same payload as the circles. What differs is
 * eighty lines of markup and a stylesheet, which is the whole argument for
 * surfaces being an interface rather than three features.
 */
class Slider extends AbstractSurface {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'slider';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Video slider', 'oc-story' );
	}

	/**
	 * Anywhere.
	 *
	 * @param array $context Request context.
	 * @return bool
	 */
	public function supports( array $context ) {
		return true;
	}

	/**
	 * Render.
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

		$inline = $this->payload_tag( $visible, $placement['id'] );

		return $this->template(
			'slider.php',
			array(
				'stories'   => $visible,
				'placement' => $placement,
				'inline'    => $inline,
				'src'       => '' === $inline ? $this->payload_src( $visible ) : '',
				'style'     => $this->card_vars( $placement ),
				'autoplay'  => \OCS\Core\Settings::is( 'card_autoplay' ),
				'heading'   => '',
			)
		);
	}
}
