<?php
/**
 * Free / Pro feature gating.
 *
 * @package OC_Story
 */

namespace OCS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Every premium capability is named here from day one, even while everything is
 * unlocked. Retro-fitting a licence split into scattered code is the expensive
 * kind of refactor; a single map is the cheap kind.
 */
class Features {

	/**
	 * Capabilities that require a licence.
	 *
	 * @var string[]
	 */
	const PRO = array(
		'multiple_placements',
		'surface_slider',
		'surface_product',
		'surface_bubble',
		'surface_grid',
		'external_video',   // Bunny / Cloudflare Stream sources.
		'analytics',
		'attribution',
		'scheduling',       // Publish and retire a story on a date.
		'ab_testing',
		'captions',
	);

	/**
	 * Runtime cache of the licence state.
	 *
	 * @var bool|null
	 */
	private static $licensed = null;

	/**
	 * Is a capability available on this site?
	 *
	 * @param string $capability Capability key.
	 * @return bool
	 */
	public static function has( $capability ) {
		if ( ! in_array( $capability, self::PRO, true ) ) {
			return true;
		}
		return (bool) apply_filters( 'ocs_has_feature', self::is_licensed(), $capability );
	}

	/**
	 * Licence state.
	 *
	 * During 0.x everything is unlocked so the plugin can be dogfooded on real
	 * stores. Flip the default here (or filter `ocs_is_licensed`) when the
	 * licence server goes live.
	 *
	 * @return bool
	 */
	public static function is_licensed() {
		if ( null === self::$licensed ) {
			self::$licensed = (bool) apply_filters( 'ocs_is_licensed', true );
		}
		return self::$licensed;
	}

	/**
	 * Human label for a capability, used by the admin UI.
	 *
	 * @param string $capability Capability key.
	 * @return string
	 */
	public static function label( $capability ) {
		$labels = array(
			'multiple_placements' => __( 'More than one placement', 'oc-story' ),
			'surface_slider'      => __( 'Video slider', 'oc-story' ),
			'surface_product'     => __( 'Product page videos', 'oc-story' ),
			'surface_bubble'      => __( 'Floating video bubble', 'oc-story' ),
			'surface_grid'        => __( 'Video wall', 'oc-story' ),
			'external_video'      => __( 'External video hosting', 'oc-story' ),
			'analytics'           => __( 'Reports and insights', 'oc-story' ),
			'attribution'         => __( 'Revenue attribution', 'oc-story' ),
			'scheduling'          => __( 'Scheduled publishing', 'oc-story' ),
			'ab_testing'          => __( 'A/B testing', 'oc-story' ),
			'captions'            => __( 'Automatic captions', 'oc-story' ),
		);
		return isset( $labels[ $capability ] ) ? $labels[ $capability ] : $capability;
	}
}
