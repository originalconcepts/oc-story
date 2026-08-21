<?php
/**
 * Manual placement.
 *
 * @package OC_Story
 */

namespace OCS\Display;

use OCS\Model\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * `[oc_story]` renders a placement wherever it is written.
 *
 * Attributes mirror a placement so a landing page can differ from the site-wide
 * bar without needing one: `[oc_story ids="11,12" size="72" labels="no"]`.
 */
class Shortcode {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'oc_story', array( $this, 'render' ) );
	}

	/**
	 * Render.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'placement' => '',
				'surface'   => 'circles',
				'ids'       => '',
				'size'      => '',
				'labels'    => '',
				'max'       => '',
				'align'     => '',
			),
			$atts,
			'oc_story'
		);

		$placement = '' !== $atts['placement'] ? Placement::get( $atts['placement'] ) : null;

		// A draft is a draft wherever it is placed. Without this, a gallery
		// saved as a draft and pasted into a page went live anyway — and the
		// screen that made it said nobody could see it yet.
		if ( $placement && empty( $placement['enabled'] ) ) {
			return '';
		}

		if ( ! $placement ) {
			$placement            = Placement::defaults();
			$placement['id']      = 'shortcode';
			$placement['surface'] = $atts['surface'];
			$placement['hook']    = 'manual';
		}

		$placement['where']['scope'] = 'site';

		if ( '' !== $atts['ids'] ) {
			$placement['stories']['mode'] = 'selected';
			$placement['stories']['ids']  = array_map( 'absint', explode( ',', $atts['ids'] ) );
		}

		foreach ( array( 'desktop', 'mobile' ) as $device ) {
			if ( '' !== $atts['size'] ) {
				$placement[ $device ]['size'] = (int) $atts['size'];
			}
			if ( '' !== $atts['labels'] ) {
				$placement[ $device ]['labels'] = in_array( strtolower( $atts['labels'] ), array( 'yes', '1', 'true' ), true );
			}
			if ( '' !== $atts['max'] ) {
				$placement[ $device ]['max'] = (int) $atts['max'];
			}
			if ( '' !== $atts['align'] ) {
				$placement[ $device ]['align'] = $atts['align'];
			}
		}

		$placement = Placement::sanitize( $placement );

		// A corner video belongs to the viewport rather than to the document,
		// so placing one by hand is placing it nowhere in particular. It is
		// still rendered — the CSS pins it to the corner either way — but the
		// surface is asked for by name rather than inherited from a default.
		// A shortcode can appear on a page the injector already skipped, so the
		// assets have to be asked for rather than assumed.
		add_filter( 'ocs_force_assets', '__return_true' );
		wp_enqueue_style( 'ocs-bar' );
		wp_enqueue_script( 'ocs-bar' );

		return Injector::render( $placement, Injector::context() );
	}
}
