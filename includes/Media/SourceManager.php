<?php
/**
 * Video source registry.
 *
 * @package OC_Story
 */

namespace OCS\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the source id stored on a slide to the object that can play it.
 *
 * Registration mirrors OC Reviews' channels and reward providers:
 *
 *     add_filter( 'ocs_video_sources', function ( $sources ) {
 *         $sources['bunny'] = new My_Bunny_Source();
 *         return $sources;
 *     } );
 */
class SourceManager {

	/**
	 * Runtime cache.
	 *
	 * @var array<string,VideoSourceInterface>|null
	 */
	private static $sources = null;

	/**
	 * Every registered source.
	 *
	 * @return array<string,VideoSourceInterface>
	 */
	public static function all() {
		if ( null === self::$sources ) {
			$sources = array( 'local' => new LocalSource() );
			$sources = apply_filters( 'ocs_video_sources', $sources );

			$clean = array();
			foreach ( (array) $sources as $id => $source ) {
				if ( $source instanceof VideoSourceInterface ) {
					$clean[ (string) $id ] = $source;
				}
			}

			// Local is not removable. A shop that filters it out by accident
			// should lose its external source, not its ability to play video.
			if ( ! isset( $clean['local'] ) ) {
				$clean['local'] = new LocalSource();
			}

			self::$sources = $clean;
		}

		return self::$sources;
	}

	/**
	 * One source, falling back to local.
	 *
	 * @param string $id Source id.
	 * @return VideoSourceInterface
	 */
	public static function get( $id ) {
		$all = self::all();
		$id  = (string) $id;

		return isset( $all[ $id ] ) ? $all[ $id ] : $all['local'];
	}

	/**
	 * The source new uploads go to.
	 *
	 * @return VideoSourceInterface
	 */
	public static function active() {
		$id = (string) apply_filters( 'ocs_active_video_source', 'local' );

		$source = self::get( $id );

		return $source->is_configured() ? $source : self::get( 'local' );
	}

	/**
	 * Drop the runtime cache.
	 */
	public static function flush() {
		self::$sources = null;
	}
}
