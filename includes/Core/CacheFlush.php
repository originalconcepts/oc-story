<?php
/**
 * Telling page caches the shop changed.
 *
 * @package OC_Story
 */

namespace OCS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A published story changes pages a cache is already holding.
 *
 * The first live install proved the cost of not doing this: every publish
 * needed someone to empty the host cache by hand, and a shop owner will not do
 * that — they will conclude the plugin is broken, because their own phone
 * keeps showing them the page from before.
 *
 * Nothing here is our cache. Our transients expire through the version stamp;
 * this reaches outward to the page caches other software keeps, through each
 * one's own public purge call, guarded so absence costs nothing. Called on
 * editorial events only — publish, placement, settings — never from product
 * saves, where purging a whole shop on every price edit would be vandalism;
 * cache plugins already handle product saves themselves.
 *
 * Host-level caches with no WordPress API (the kind a panel purges) cannot be
 * reached from here. `ocs_caches_flushed` fires so a host integration can
 * bridge that gap.
 */
class CacheFlush {

	/**
	 * Ask every known page cache to drop its pages.
	 */
	public static function pages() {
		if ( ! apply_filters( 'ocs_flush_page_caches', true ) ) {
			return;
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_all' );

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}

		// SiteGround Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// Cache Enabler.
		do_action( 'cache_enabler_clear_complete_cache' );

		// Breeze (Cloudways).
		do_action( 'breeze_clear_all_cache' );

		// Hummingbird.
		do_action( 'wphb_clear_page_cache' );

		// WP-Optimize.
		if ( class_exists( '\WP_Optimize' ) && function_exists( 'wpo_cache_flush' ) ) {
			wpo_cache_flush();
		}

		// Anything else — including host caches that need an API call WordPress
		// cannot make — hooks here.
		do_action( 'ocs_caches_flushed' );
	}
}
