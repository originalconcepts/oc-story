<?php
/**
 * The circles bar.
 *
 * @package OC_Story
 */

namespace OCS\Surfaces;

defined( 'ABSPATH' ) || exit;

/**
 * The row of circles at the top of a page.
 *
 * This is the surface the plugin is judged on, and it carries the whole
 * front-end budget: a poster, a caption and nothing else. No video is
 * referenced here at all — a visitor who never taps a circle downloads not one
 * byte of it.
 */
class Circles extends AbstractSurface {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'circles';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Story circles', 'oc-story' );
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
	 * Render the bar.
	 *
	 * @param array $stories   Stories.
	 * @param array $placement Placement.
	 * @return string
	 */
	public function render( array $stories, array $placement ) {
		$payload = $this->payload( $stories );

		if ( ! $payload ) {
			return '';
		}

		// Only stories with something playable reach the bar. A circle that
		// opens onto nothing is worse than a shorter row.
		$playable = array();
		foreach ( $payload as $story ) {
			$playable[] = (int) $story['i'];
		}

		$visible = array();
		foreach ( $stories as $story ) {
			if ( in_array( (int) $story['id'], $playable, true ) ) {
				$visible[] = $story;
			}
		}

		$inline = $this->payload_tag( $visible, $placement['id'] );

		return $this->template(
			'circles.php',
			array(
				'stories'   => $visible,
				'placement' => $placement,
				'inline'    => $inline,
				'src'       => '' === $inline ? $this->payload_src( $visible ) : '',
				'style'     => $this->style_vars( $placement ),
				'surface'   => $this,
			)
		);
	}

	/**
	 * Public so the template can reach it.
	 *
	 * @param array $placement Placement.
	 * @return string
	 */
	public function vars( array $placement ) {
		return $this->style_vars( $placement );
	}
}
