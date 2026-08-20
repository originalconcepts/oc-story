<?php
/**
 * Front-end asset loading.
 *
 * @package OC_Story
 */

namespace OCS\Display;

use OCS\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The whole front-end budget lives here.
 *
 * One deferred script of about four kilobytes and a stylesheet small enough to
 * inline. The player and its stylesheet are a separate chunk that `bar.js`
 * imports on the first tap, so a visitor who scrolls past the circles pays for
 * the circles and nothing else. See PLAN.md §10 for the numbers, and
 * tests/budget.mjs for the check that keeps them true.
 */
class Assets {

	/**
	 * Cached critical CSS, keyed by the set of surfaces it covers.
	 *
	 * @var array<string,string>
	 */
	private static $critical = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Late, so Injector::route() on `wp` has already decided.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
		add_filter( 'script_loader_tag', array( $this, 'add_attributes' ), 10, 2 );
	}

	/**
	 * Load nothing unless something renders.
	 */
	public function maybe_enqueue() {
		// Attribution has to work on a product page even when no surface
		// renders there — the shopper arrived from a story somewhere else.
		// One deferred script, ~400 bytes, product pages only.
		if (
			function_exists( 'is_product' ) && is_product()
			&& Settings::is( 'analytics_enabled' ) && Settings::is( 'attribution_enabled' )
		) {
			wp_enqueue_script( 'ocs-attr', OCS_URL . 'assets/js/attr.js', array(), OCS_VERSION, true );
			wp_add_inline_script(
				'ocs-attr',
				'window.ocsAttrCfg=' . wp_json_encode(
					array( 'window' => \OCS\Model\Attribution::window_seconds() * 1000 )
				) . ';',
				'before'
			);
		}

		if ( $this->has_shortcode() ) {
			// A shortcode runs during `the_content`, long after this. Which
			// surface it will ask for is not knowable yet, so all of them are
			// covered — a few hundred bytes, on the minority of pages that use
			// one, rather than a bar with no styling.
			foreach ( \OCS\Surfaces\SurfaceManager::ids() as $id ) {
				Injector::need( $id );
			}
		}

		if ( ! Injector::anything() && ! apply_filters( 'ocs_force_assets', false ) ) {
			return;
		}

		wp_register_style( 'ocs-bar', false, array(), OCS_VERSION );
		wp_enqueue_style( 'ocs-bar' );
		wp_add_inline_style( 'ocs-bar', $this->critical_css() . $this->theme_vars() );

		wp_enqueue_script( 'ocs-bar', OCS_URL . 'assets/js/bar.js', array(), OCS_VERSION, true );

		// The preview chunk rides along only where a card surface is actually
		// rendering and previews are switched on — never on a circles-only
		// page, and never at all when the setting is off.
		if ( Settings::is( 'card_autoplay' ) && array_intersect( array( 'slider', 'grid', 'product' ), Injector::surfaces() ) ) {
			wp_enqueue_script( 'ocs-preview', OCS_URL . 'assets/js/preview.js', array(), OCS_VERSION, true );
		}

		// The player chunk is imported by URL, so the path has to survive a
		// child theme, a CDN rewrite and a plugins directory that is not where
		// anyone expects it to be.
		wp_add_inline_script(
			'ocs-bar',
			'window.ocsCfg=' . wp_json_encode(
				array(
					'player' => OCS_URL . 'assets/js/player.js?v=' . OCS_VERSION,
					'css'    => OCS_URL . 'assets/css/player.css?v=' . OCS_VERSION,
					'events' => Settings::is( 'analytics_enabled' ) ? rest_url( 'oc-story/v1/events' ) : '',
					'api'    => rest_url( 'oc-story/v1' ),
					'ring'   => Settings::get( 'ring_style', 'gradient' ),
					'next'   => Settings::is( 'advance_to_next_story' ),
					'nav'    => (string) Settings::get( 'gallery_nav', 'arrows' ),
					'dim'    => 'dim' === Settings::get( 'backdrop', 'dim' ),
					'i18n'   => array(
						'close' => __( 'Close', 'oc-story' ),
						'prev'  => __( 'Previous', 'oc-story' ),
						'next'  => __( 'Next', 'oc-story' ),
						'shop'  => __( 'View product', 'oc-story' ),
						'buy'         => __( 'Buy', 'oc-story' ),
						'add'         => __( 'Add to cart', 'oc-story' ),
						'added'       => __( 'Added ✓', 'oc-story' ),
						'unavailable' => __( 'Unavailable', 'oc-story' ),
						'spark'       => __( 'Spark', 'oc-story' ),
						'like'        => __( 'Like', 'oc-story' ),
						'sparkHint'   => __( 'Mark the one that caught you', 'oc-story' ),
						'prevGallery' => __( 'Previous gallery', 'oc-story' ),
						'nextGallery' => __( 'Next gallery', 'oc-story' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The shop's ring colours as custom-property overrides.
	 *
	 * Same philosophy as OC Reviews: everything visual is a variable, so a
	 * jeweller and a butcher both install this and it looks like theirs.
	 * Nothing is emitted when the defaults are untouched.
	 *
	 * @return string
	 */
	protected function theme_vars() {
		$style = (string) Settings::get( 'ring_style', 'gradient' );
		$vars  = array();

		if ( 'solid' === $style ) {
			$ring = sanitize_hex_color( (string) Settings::get( 'ring_color', '' ) );
			if ( $ring ) {
				$vars[] = '--ocs-ring:' . $ring;
			}
		} elseif ( 'none' === $style ) {
			$vars[] = '--ocs-ring:transparent';
		}

		$seen = sanitize_hex_color( (string) Settings::get( 'ring_seen_color', '' ) );
		if ( $seen && '#c7c7c7' !== $seen ) {
			$vars[] = '--ocs-seen:' . $seen;
		}

		if ( ! $vars ) {
			return '';
		}

		return '.ocs-bar{' . implode( ';', $vars ) . '}';
	}

	/**
	 * Is there an `[oc_story]` in the content of this page?
	 *
	 * Shortcodes run during `the_content`, long after styles have been printed,
	 * so the stylesheet has to be decided here or it never makes it into head.
	 *
	 * @return bool
	 */
	protected function has_shortcode() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		if ( ! $post ) {
			return false;
		}

		return has_shortcode( $post->post_content, 'oc_story' )
			|| ( function_exists( 'has_block' ) && has_block( 'oc-story/stories', $post ) );
	}

	/**
	 * Mark our script so nothing else defers, delays or lazy-loads it.
	 *
	 * Optimisation plugins routinely rewrite third-party scripts to load on
	 * first interaction. Doing that to the script whose entire job is to answer
	 * the first interaction produces a circle that does nothing when tapped.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @return string
	 */
	public function add_attributes( $tag, $handle ) {
		if ( 'ocs-bar' !== $handle ) {
			return $tag;
		}

		return str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" ', $tag );
	}

	/**
	 * The inlined critical stylesheet.
	 *
	 * Inlined rather than linked because it is two kilobytes and the bar is
	 * usually the first thing on the page: a separate request for it would be a
	 * round trip in front of the largest contentful paint.
	 *
	 * @return string
	 */
	protected function critical_css() {
		$surfaces = Injector::surfaces();

		if ( ! $surfaces ) {
			$surfaces = array( 'circles' );
		}

		sort( $surfaces );
		$key = implode( ',', $surfaces );

		if ( ! isset( self::$critical[ $key ] ) ) {
			$css = '';

			foreach ( $surfaces as $id ) {
				$file = OCS_PATH . 'assets/css/surface-' . sanitize_key( $id ) . '.css';

				if ( is_readable( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
					$css .= (string) file_get_contents( $file );
				}
			}

			self::$critical[ $key ] = $css;
		}

		return self::$critical[ $key ];
	}
}
