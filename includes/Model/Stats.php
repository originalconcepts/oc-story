<?php
/**
 * The daily rollup.
 *
 * @package OC_Story
 */

namespace OCS\Model;

use OCS\Core\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Counters, never events.
 *
 * A row per view would grow without bound on a busy shop and buy nothing these
 * counters cannot answer. Everything lands in `ocs_stats_daily` through one
 * upsert against the unique bucket key, so there is no cron to fall behind and
 * no raw table to prune.
 *
 * Bar-level reach is stored with `story_id = 0`: one row per placement per day
 * rather than one per story shown, which is the difference between a busy shop
 * writing one counter per pageview and writing twelve.
 */
class Stats {

	/**
	 * Client event types, mapped to their counter columns.
	 *
	 * `add_to_cart`, `orders` and `revenue` are deliberately absent: money is
	 * only ever counted server-side, where a request cannot invent it.
	 *
	 * @var array<string,string>
	 */
	const CLIENT_EVENTS = array(
		'i' => 'impressions',
		'o' => 'opens',
		'd' => 'completions',
		'p' => 'product_taps',
		'k' => 'sparks',
		'h' => 'likes',
	);

	/**
	 * Largest batch one beacon may carry.
	 */
	const MAX_BATCH = 50;

	/**
	 * Coerce a raw beacon payload into rollup rows.
	 *
	 * Pure, and deliberately so: this is the entire safety boundary of a
	 * public, nonce-free endpoint, so it is the part the harness pins down.
	 * Anything unrecognised is dropped, never guessed at. Output is keyed rows
	 * with counts already aggregated, ready for one upsert each.
	 *
	 * @param mixed    $batch    Decoded request body.
	 * @param string[] $surfaces Registered surface ids.
	 * @return array<string,array> Keyed by bucket, values: bucket fields + counts.
	 */
	public static function normalize_batch( $batch, array $surfaces ) {
		if ( ! is_array( $batch ) ) {
			return array();
		}

		$rows = array();
		$seen = 0;

		foreach ( $batch as $event ) {
			if ( ++$seen > self::MAX_BATCH ) {
				break;
			}

			if ( ! is_array( $event ) ) {
				continue;
			}

			$type = isset( $event['t'] ) ? (string) $event['t'] : '';
			if ( ! isset( self::CLIENT_EVENTS[ $type ] ) ) {
				continue;
			}

			$story = isset( $event['s'] ) ? (int) $event['s'] : -1;
			if ( $story < 0 || $story > PHP_INT_MAX / 2 ) {
				continue;
			}

			// Reach rows are the only ones allowed a zero story.
			if ( 0 === $story && 'i' !== $type ) {
				continue;
			}

			$slide = isset( $event['l'] ) ? (string) $event['l'] : '';
			if ( '' !== $slide && ! preg_match( '/^s_[a-f0-9]{8}$/', $slide ) ) {
				continue;
			}

			$surface = isset( $event['f'] ) ? (string) $event['f'] : '';
			if ( '' !== $surface && ! in_array( $surface, $surfaces, true ) ) {
				continue;
			}

			$device = ( isset( $event['d'] ) && 'm' === $event['d'] ) ? 'm' : 'd';

			$key = $story . '|' . $slide . '|' . $surface . '|' . $device;

			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'story_id' => $story,
					'slide_id' => $slide,
					'surface'  => $surface,
					'device'   => $device,
					'counts'   => array(),
				);
			}

			$column = self::CLIENT_EVENTS[ $type ];

			$rows[ $key ]['counts'][ $column ] = ( $rows[ $key ]['counts'][ $column ] ?? 0 ) + 1;
		}

		return $rows;
	}

	/**
	 * Apply normalised rows to today's bucket.
	 *
	 * @param array $rows Rows from normalize_batch(), or shaped the same way.
	 */
	public static function bump( array $rows ) {
		global $wpdb;

		$table = Install::table( 'stats_daily' );
		$day   = current_time( 'Y-m-d' );

		$counters = array( 'impressions', 'opens', 'completions', 'product_taps', 'sparks', 'likes', 'add_to_cart', 'orders' );

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $counters as $counter ) {
				$values[ $counter ] = max( 0, (int) ( $row['counts'][ $counter ] ?? 0 ) );
			}
			$revenue = round( (float) ( $row['counts']['revenue'] ?? 0 ), 4 );

			if ( ! array_sum( $values ) && $revenue <= 0 ) {
				continue;
			}

			// One statement per bucket: insert the row or add to its counters.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
						(day, story_id, slide_id, surface, device,
						 impressions, opens, completions, product_taps, sparks, likes, add_to_cart, orders, revenue)
					 VALUES (%s, %d, %s, %s, %s, %d, %d, %d, %d, %d, %d, %d, %d, %f)
					 ON DUPLICATE KEY UPDATE
						impressions  = impressions  + VALUES(impressions),
						opens        = opens        + VALUES(opens),
						completions  = completions  + VALUES(completions),
						product_taps = product_taps + VALUES(product_taps),
						sparks       = sparks       + VALUES(sparks),
						likes        = likes        + VALUES(likes),
						add_to_cart  = add_to_cart  + VALUES(add_to_cart),
						orders       = orders       + VALUES(orders),
						revenue      = revenue      + VALUES(revenue)",
					$day,
					(int) $row['story_id'],
					(string) $row['slide_id'],
					(string) $row['surface'],
					(string) $row['device'],
					$values['impressions'],
					$values['opens'],
					$values['completions'],
					$values['product_taps'],
					$values['sparks'],
					$values['likes'],
					$values['add_to_cart'],
					$values['orders'],
					$revenue
				)
			);
		}
	}

	/**
	 * Per-story totals over a period, newest earners first.
	 *
	 * @param int $days Days back from today.
	 * @return array<int,array>
	 */
	public static function by_story( $days = 30 ) {
		global $wpdb;

		$table = Install::table( 'stats_daily' );
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . max( 1, (int) $days ) . ' days' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT story_id,
						SUM(opens) AS opens,
						SUM(completions) AS completions,
						SUM(product_taps) AS product_taps,
						SUM(sparks) AS sparks,
						SUM(likes) AS likes,
						SUM(add_to_cart) AS add_to_cart,
						SUM(orders) AS orders,
						SUM(revenue) AS revenue
				 FROM {$table}
				 WHERE day >= %s AND story_id > 0
				 GROUP BY story_id
				 ORDER BY revenue DESC, opens DESC",
				$since
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'story_id'     => (int) $row['story_id'],
					'opens'        => (int) $row['opens'],
					'completions'  => (int) $row['completions'],
					'product_taps' => (int) $row['product_taps'],
					'sparks'       => (int) $row['sparks'],
					'likes'        => (int) $row['likes'],
					'add_to_cart'  => (int) $row['add_to_cart'],
					'orders'       => (int) $row['orders'],
					'revenue'      => (float) $row['revenue'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Lifetime sparks and likes for a set of galleries, per slide.
	 *
	 * The player shows these as social proof, so they ride along in the bar's
	 * payload — one grouped query on a render that is cached for twelve hours,
	 * rather than a request per open.
	 *
	 * @param int[] $story_ids Gallery IDs.
	 * @return array<string,array> Keyed "storyId:slideId".
	 */
	public static function reactions( array $story_ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $story_ids ) ) );

		if ( ! $ids ) {
			return array();
		}

		$table        = Install::table( 'stats_daily' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT story_id, slide_id, SUM(sparks) AS sparks, SUM(likes) AS likes
				 FROM {$table}
				 WHERE story_id IN ({$placeholders}) AND slide_id != ''
				 GROUP BY story_id, slide_id",
				$ids
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['story_id'] . ':' . $row['slide_id'] ] = array(
				'sparks' => (int) $row['sparks'],
				'likes'  => (int) $row['likes'],
			);
		}

		return $out;
	}

	/**
	 * Bar reach over a period: how many pageviews saw a bar at all.
	 *
	 * @param int $days Days back from today.
	 * @return int
	 */
	public static function reach( $days = 30 ) {
		global $wpdb;

		$table = Install::table( 'stats_daily' );
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . max( 1, (int) $days ) . ' days' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT SUM(impressions) FROM {$table} WHERE day >= %s AND story_id = 0", $since )
		);
	}

	/**
	 * Opens per story over the last seven days, for the studio cards.
	 *
	 * One grouped query for the whole list — never a query per card.
	 *
	 * @return array<int,int> story_id => opens.
	 */
	public static function opens_last_week() {
		global $wpdb;

		$table = Install::table( 'stats_daily' );
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -7 days' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT story_id, SUM(opens) AS opens FROM {$table} WHERE day >= %s AND story_id > 0 GROUP BY story_id",
				$since
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['story_id'] ] = (int) $row['opens'];
		}

		return $out;
	}
}
