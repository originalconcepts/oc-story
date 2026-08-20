<?php
/**
 * The site's own media library.
 *
 * @package OC_Story
 */

namespace OCS\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Stores video as ordinary WordPress attachments.
 *
 * The reference is the attachment ID as a string. Keeping it a string rather
 * than an int is what lets an external source hand back an opaque id later
 * without changing the slide shape.
 */
class LocalSource implements VideoSourceInterface {

	const META_DIMENSIONS = '_ocs_dimensions';

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'local';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'This site (media library)', 'oc-story' );
	}

	/**
	 * Always available.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Move a finished upload into the media library.
	 *
	 * @param string $path File path on disk.
	 * @param array  $meta Keys: filename, mime, w, h, duration.
	 * @return string|\WP_Error
	 */
	public function ingest( $path, array $meta ) {
		if ( ! is_file( $path ) ) {
			return new \WP_Error( 'ocs_missing_file', __( 'The uploaded file could not be found.', 'oc-story' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = isset( $meta['filename'] ) ? sanitize_file_name( (string) $meta['filename'] ) : 'story.mp4';
		if ( '' === $filename ) {
			$filename = 'story.mp4';
		}

		$overrides = array(
			'test_form' => false,
			'action'    => 'ocs_upload',
			'mimes'     => Probe::allowed_upload_mimes(),
		);

		$file = array(
			'name'     => $filename,
			'type'     => isset( $meta['mime'] ) ? (string) $meta['mime'] : 'video/mp4',
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => (int) filesize( $path ),
		);

		$handled = wp_handle_sideload( $file, $overrides );

		if ( isset( $handled['error'] ) ) {
			return new \WP_Error( 'ocs_sideload', (string) $handled['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $handled['type'],
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$handled['file']
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $handled['file'] );
			return new \WP_Error( 'ocs_attach', __( 'The video could not be added to the media library.', 'oc-story' ) );
		}

		// WordPress generates nothing useful for video, and probing the file for
		// dimensions costs a shell we may not have. The studio already knows the
		// numbers because it encoded the file, so we take them from there.
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'    => isset( $meta['w'] ) ? (int) $meta['w'] : 0,
				'height'   => isset( $meta['h'] ) ? (int) $meta['h'] : 0,
				'length'   => isset( $meta['duration'] ) ? (int) round( (float) $meta['duration'] ) : 0,
				'filesize' => (int) filesize( $handled['file'] ),
			)
		);

		update_post_meta(
			$attachment_id,
			self::META_DIMENSIONS,
			array(
				'w'        => isset( $meta['w'] ) ? (int) $meta['w'] : 0,
				'h'        => isset( $meta['h'] ) ? (int) $meta['h'] : 0,
				'duration' => isset( $meta['duration'] ) ? round( (float) $meta['duration'], 2 ) : 0.0,
			)
		);

		return (string) $attachment_id;
	}

	/**
	 * Playback URL.
	 *
	 * @param string $ref Attachment ID.
	 * @return string
	 */
	public function get_playback_url( $ref ) {
		$url = wp_get_attachment_url( (int) $ref );
		return $url ? $url : '';
	}

	/**
	 * Delete the attachment and its file.
	 *
	 * @param string $ref Attachment ID.
	 * @return bool
	 */
	public function delete( $ref ) {
		return (bool) wp_delete_attachment( (int) $ref, true );
	}
}
