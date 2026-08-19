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
	 * Cached critical CSS.
	 *
	 * @var string|null
	 */
	private static $critical = null;

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
		if ( ! Injector::anything() && ! $this->has_shortcode() && ! apply_filters( 'ocs_force_assets', false ) ) {
			return;
		}

		wp_register_style( 'ocs-bar', false, array(), OCS_VERSION );
		wp_enqueue_style( 'ocs-bar' );
		wp_add_inline_style( 'ocs-bar', $this->critical_css() );

		wp_enqueue_script( 'ocs-bar', OCS_URL . 'assets/js/bar.js', array(), OCS_VERSION, true );

		// The player chunk is imported by URL, so the path has to survive a
		// child theme, a CDN rewrite and a plugins directory that is not where
		// anyone expects it to be.
		wp_add_inline_script(
			'ocs-bar',
			'window.ocsCfg=' . wp_json_encode(
				array(
					'player' => OCS_URL . 'assets/js/player.js',
					'css'    => OCS_URL . 'assets/css/player.css?v=' . OCS_VERSION,
					'ring'   => Settings::get( 'ring_style', 'gradient' ),
					'next'   => Settings::is( 'advance_to_next_story' ),
					'i18n'   => array(
						'close' => __( 'Close', 'oc-story' ),
						'prev'  => __( 'Previous', 'oc-story' ),
						'next'  => __( 'Next', 'oc-story' ),
						'shop'  => __( 'View product', 'oc-story' ),
					),
				)
			) . ';',
			'before'
		);
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
		if ( null === self::$critical ) {
			$file = OCS_PATH . 'assets/css/bar.css';

			self::$critical = is_readable( $file )
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
				? (string) file_get_contents( $file )
				: '';
		}

		return self::$critical;
	}
}
