<?php
/**
 * Did the gallery actually land where the picture said it would?
 *
 * @package OC_Story
 */

namespace OCS\Display;

use OCS\Core\Install;
use OCS\Model\Positions;

defined( 'ABSPATH' ) || exit;

/**
 * The wizard shows a drawing of a page with the gallery in a spot. That is a
 * promise, and half the spots it can promise only exist while the theme still
 * renders WooCommerce's own templates — a product page built in a page builder
 * replaces the summary wholesale and none of those hooks ever fire.
 *
 * So after publishing, the shop asks itself. One request to one real page of
 * the right kind, looking for the gallery's own marker in the HTML that comes
 * back. Found, missing, or — when the shop cannot reach itself, which some
 * hosts arrange — honestly unknown.
 *
 * It runs once, on publishing, and never during anybody's visit.
 */
class SpotCheck {

	/**
	 * Look for a published gallery on a page it claims to be on.
	 *
	 * @param array $placement Sanitised placement.
	 * @return array { status: found|missing|unknown|skipped, url, reason }
	 */
	public static function run( array $placement ) {
		if ( empty( $placement['enabled'] ) ) {
			return self::result( 'skipped', '', __( 'A draft is not on the shop yet, so there is nothing to look for.', 'oc-story' ) );
		}

		if ( 'manual' === $placement['hook'] ) {
			return self::result( 'skipped', '', __( 'You are placing this one yourself, so there is no spot to check.', 'oc-story' ) );
		}

		$url = self::sample_url( $placement );

		if ( '' === $url ) {
			return self::result( 'unknown', '', __( 'No page of that kind exists yet to look at.', 'oc-story' ) );
		}

		// A query argument nothing reads, purely so no cache — ours, a plugin's
		// or the host's — can answer this with a copy made before publishing.
		$probe = add_query_arg( 'ocs_check', (string) time(), $url );

		$response = wp_remote_get(
			$probe,
			array(
				'timeout'    => 12,
				'sslverify'  => false,
				'user-agent' => 'OC Story position check',
				'headers'    => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result( 'unknown', $url, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code. */
			return self::result( 'unknown', $url, sprintf( __( 'That page answered %d.', 'oc-story' ), $code ) );
		}

		$html   = (string) wp_remote_retrieve_body( $response );
		$marker = 'data-ocs-bar="' . $placement['id'] . '"';

		if ( false !== strpos( $html, $marker ) ) {
			return self::result( 'found', $url, '' );
		}

		return self::result(
			'missing',
			$url,
			Positions::needs_theme_support( $placement['position'] )
				? __( 'Your theme does not have that spot — it builds this page its own way. Choose another spot, or place the gallery yourself with the shortcode.', 'oc-story' )
				: __( 'The gallery did not appear there. Check that it has at least one published video.', 'oc-story' )
		);
	}

	/**
	 * One real page of the kind this gallery is aimed at.
	 *
	 * @param array $placement Placement.
	 * @return string URL, or '' when the shop has no such page.
	 */
	protected static function sample_url( array $placement ) {
		$ids = isset( $placement['where']['ids'] ) ? (array) $placement['where']['ids'] : array();

		switch ( $placement['target'] ) {
			case 'home':
			case 'site':
				return home_url( '/' );

			case 'page':
				return $ids ? (string) get_permalink( (int) $ids[0] ) : '';

			case 'category':
				if ( ! $ids ) {
					return '';
				}

				$link = get_term_link( (int) $ids[0], 'product_cat' );

				return is_wp_error( $link ) ? '' : (string) $link;

			case 'product':
				// Named products are easy. On automatic, the gallery only shows
				// where its own videos tag something — so the page to look at
				// is one of those, not any product in the shop. Checking a
				// product the videos never mention would report "missing" for a
				// gallery that works perfectly.
				$product = $ids ? (int) $ids[0] : self::first_tagged( $placement );

				return $product ? (string) get_permalink( $product ) : '';
		}

		return '';
	}

	/**
	 * A product that one of this gallery's videos tags.
	 *
	 * @param array $placement Placement.
	 * @return int Product ID, or 0.
	 */
	protected static function first_tagged( array $placement ) {
		global $wpdb;

		$stories = isset( $placement['stories']['ids'] ) ? array_map( 'absint', (array) $placement['stories']['ids'] ) : array();
		$stories = array_values( array_filter( $stories ) );

		if ( ! $stories ) {
			return 0;
		}

		$table        = Install::table( 'slide_product' );
		$placeholders = implode( ',', array_fill( 0, count( $stories ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT product_id FROM {$table} WHERE story_id IN ({$placeholders}) LIMIT 20",
				$stories
			)
		);

		foreach ( (array) $found as $product_id ) {
			if ( 'publish' === get_post_status( (int) $product_id ) ) {
				return (int) $product_id;
			}
		}

		return 0;
	}

	/**
	 * Shape one answer.
	 *
	 * @param string $status found|missing|unknown|skipped.
	 * @param string $url    The page looked at.
	 * @param string $reason Why, in words for a shop owner.
	 * @return array
	 */
	protected static function result( $status, $url, $reason ) {
		return array(
			'status' => $status,
			'url'    => $url,
			'reason' => $reason,
		);
	}
}
