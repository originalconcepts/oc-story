<?php
/**
 * Data-layer hooks: post type registration and cleanup.
 *
 * @package OC_Story
 */

namespace OCS\Model;

use OCS\Core\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the stored data consistent with the posts it describes.
 *
 * Split out of Plugin so that the bootstrap stays a list of what loads rather
 * than a place where behaviour accumulates.
 */
class StoryHooks {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( \OCS\Model\Story::class, 'register' ) );

		// Index rows outlive the post unless we remove them. `before_delete_post`
		// rather than `deleted_post` so the slides are still readable if a future
		// version needs to inspect them on the way out.
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );

		add_action( 'ocs_daily_maintenance', array( $this, 'sweep_uploads' ) );
	}

	/**
	 * Clear the product index when a story is deleted.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 */
	public function on_delete( $post_id, $post = null ) {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( Story::POST_TYPE !== $type ) {
			return;
		}

		Story::clear_index( $post_id );
	}

	/**
	 * Remove abandoned upload sessions and their temp files.
	 *
	 * A dropped mobile connection mid-upload leaves a partial file behind. Six
	 * hours is long enough that a slow upload is never swept out from under a
	 * user, and short enough that the disk does not fill.
	 */
	public function sweep_uploads() {
		global $wpdb;

		$table = Install::table( 'uploads' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, tmp_path FROM {$table} WHERE expires_at < %s LIMIT 500", $now ),
			ARRAY_A
		);

		if ( ! $rows ) {
			return;
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$path = (string) $row['tmp_path'];
			if ( '' !== $path && is_file( $path ) ) {
				wp_delete_file( $path );
			}
			$ids[] = (int) $row['id'];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}
}
