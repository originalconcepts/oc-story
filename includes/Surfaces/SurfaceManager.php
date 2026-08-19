<?php
/**
 * Surface registry.
 *
 * @package OC_Story
 */

namespace OCS\Surfaces;

defined( 'ABSPATH' ) || exit;

/**
 * Registration mirrors the video sources and OC Reviews' channels:
 *
 *     add_filter( 'ocs_surfaces', function ( $surfaces ) {
 *         $surfaces['wall'] = new My_Wall_Surface();
 *         return $surfaces;
 *     } );
 */
class SurfaceManager {

	/**
	 * Runtime cache.
	 *
	 * @var array<string,SurfaceInterface>|null
	 */
	private static $surfaces = null;

	/**
	 * Every registered surface.
	 *
	 * @return array<string,SurfaceInterface>
	 */
	public static function all() {
		if ( null === self::$surfaces ) {
			$surfaces = array( 'circles' => new Circles() );
			$surfaces = apply_filters( 'ocs_surfaces', $surfaces );

			$clean = array();
			foreach ( (array) $surfaces as $id => $surface ) {
				if ( $surface instanceof SurfaceInterface ) {
					$clean[ (string) $id ] = $surface;
				}
			}

			self::$surfaces = $clean;
		}

		return self::$surfaces;
	}

	/**
	 * One surface.
	 *
	 * @param string $id Surface id.
	 * @return SurfaceInterface|null
	 */
	public static function get( $id ) {
		$all = self::all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Registered ids, for placement validation.
	 *
	 * @return string[]
	 */
	public static function ids() {
		return array_keys( self::all() );
	}

	/**
	 * Drop the runtime cache.
	 */
	public static function flush() {
		self::$surfaces = null;
	}
}
