<?php
/**
 * Settings access.
 *
 * @package OC_Story
 */

namespace OCS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Single option array, typed accessors, sane defaults.
 *
 * Per-surface layout lives on the placement, not here — a shop can have circles
 * on the home page and a slider on a landing page with different sizes. What
 * lives here is global: encoding targets, colours, and the defaults a new
 * placement is seeded with.
 */
class Settings {

	const OPTION = 'ocs_settings';

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// --- Encoding (client side) ----------------------------------------
			// The studio re-encodes on the device before uploading. These are the
			// targets it aims for; see PLAN.md section 4.
			'encode_enabled'         => 'yes',
			'max_long_side'          => 1280,
			'target_bitrate'         => 1500000,
			'target_fps'             => 30,
			'audio_bitrate'          => 96000,
			'max_slide_seconds'      => 60,
			'max_upload_mb'          => 200,   // Pre-encode ceiling, guards a mistake.
			'chunk_size_bytes'       => 2097152,

			// --- Player ---------------------------------------------------------
			'default_slide_seconds'  => 7,     // Used when a slide has no duration.
			'muted_first_frame'      => 'yes', // Required for autoplay on iOS.
			'loop_last_slide'        => 'no',
			'advance_to_next_story'  => 'yes',
			'show_product_strip'     => 'yes',
			// Cards preview themselves, silently, one at a time. See
			// assets/js/preview.js for how that stays cheap.
			'card_autoplay'          => 'yes',
			'close_on_finish'        => 'yes',

			// --- Look ------------------------------------------------------------
			'accent_color'           => '',
			'ring_style'             => 'gradient', // 'gradient' | 'solid' | 'none'.
			'ring_color'             => '#d6249f',
			'ring_seen_color'        => '#c7c7c7',
			'label_max_chars'        => 14,

			// --- Defaults for a new placement -------------------------------------
			'desktop_size'           => 84,
			'desktop_labels'         => 'yes',
			'desktop_max'            => 12,
			'mobile_size'            => 64,
			'mobile_labels'          => 'yes',
			'mobile_max'             => 20,

			// --- Analytics ---------------------------------------------------------
			'analytics_enabled'      => 'yes',
			'attribution_enabled'    => 'yes',
			'attribution_days'       => 7,

			// --- Housekeeping --------------------------------------------------------
			'delete_data_on_uninstall' => 'no',
		);
	}

	/**
	 * Whole settings array, defaults merged in.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * A yes/no setting as a boolean.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * An integer setting, clamped.
	 *
	 * @param string   $key Setting key.
	 * @param int|null $min Minimum.
	 * @param int|null $max Maximum.
	 * @return int
	 */
	public static function int( $key, $min = null, $max = null ) {
		$value = (int) self::get( $key, 0 );
		if ( null !== $min && $value < $min ) {
			$value = $min;
		}
		if ( null !== $max && $value > $max ) {
			$value = $max;
		}
		return $value;
	}

	/**
	 * Persist a partial update.
	 *
	 * @param array $values Key/value pairs.
	 * @return bool
	 */
	public static function update( array $values ) {
		$merged = array_merge( self::all(), $values );
		$merged = array_intersect_key( $merged, self::defaults() );

		self::$cache = $merged;
		return update_option( self::OPTION, $merged, false );
	}

	/**
	 * Write defaults on activation without clobbering an existing config.
	 */
	public static function install_defaults() {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
			return;
		}
		update_option( self::OPTION, wp_parse_args( $stored, self::defaults() ), false );
	}

	/**
	 * Drop the runtime cache. Tests and the settings screen need this.
	 */
	public static function flush() {
		self::$cache = null;
	}
}
