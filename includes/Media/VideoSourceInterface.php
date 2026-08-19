<?php
/**
 * Where video files live.
 *
 * @package OC_Story
 */

namespace OCS\Media;

defined( 'ABSPATH' ) || exit;

/**
 * The seam between the plugin and video storage.
 *
 * 0.1 ships `LocalSource` only — the site's own media library, no account, no
 * cost, works everywhere. Bunny Stream and Cloudflare Stream arrive behind this
 * interface without touching anything upstream of it, which is the entire reason
 * it exists this early.
 */
interface VideoSourceInterface {

	/**
	 * Machine id, stored on every slide.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human label for the settings screen.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Is this source usable on this site right now?
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Take ownership of a finished upload.
	 *
	 * @param string $path File path on disk.
	 * @param array  $meta Keys: filename, mime, w, h, duration.
	 * @return string|\WP_Error Storage reference, or an error.
	 */
	public function ingest( $path, array $meta );

	/**
	 * Playback URL for a reference.
	 *
	 * @param string $ref Storage reference.
	 * @return string
	 */
	public function get_playback_url( $ref );

	/**
	 * Remove a stored video.
	 *
	 * @param string $ref Storage reference.
	 * @return bool
	 */
	public function delete( $ref );
}
