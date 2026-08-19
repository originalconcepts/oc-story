<?php
/**
 * Poster frames.
 *
 * @package OC_Story
 */

namespace OCS\Media;

defined( 'ABSPATH' ) || exit;

/**
 * The still frame that stands in for a video until it plays.
 *
 * WordPress generates nothing for a video attachment, so the poster is our own
 * attachment linked back to the video. It carries most of the plugin's visible
 * performance: the circles bar is nothing but posters, and the player paints one
 * instantly on tap while the video is still deciding to start.
 *
 * The studio captures it from the decoded frame at t=0.1s, which costs the
 * server nothing and is always in sync with the video it represents.
 */
class Poster {

	const META_FOR = '_ocs_poster_for';

	/**
	 * Accepted poster types.
	 *
	 * @var array<string,string>
	 */
	const MIMES = array(
		'webp' => 'image/webp',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	/**
	 * Largest poster we will store, before it is obviously not a poster.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Store a poster and link it to its video.
	 *
	 * @param string $bytes    Raw image bytes.
	 * @param string $mime     Declared mime type.
	 * @param int    $video_id Video attachment ID, 0 if not yet known.
	 * @param string $title    Attachment title.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function store( $bytes, $mime, $video_id = 0, $title = '' ) {
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return new \WP_Error( 'ocs_poster_empty', __( 'The poster image was empty.', 'oc-story' ), array( 'status' => 400 ) );
		}

		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return new \WP_Error( 'ocs_poster_large', __( 'The poster image was too large.', 'oc-story' ), array( 'status' => 413 ) );
		}

		$mime = strtolower( (string) $mime );
		$ext  = array_search( $mime, self::MIMES, true );
		if ( false === $ext ) {
			return new \WP_Error( 'ocs_poster_mime', __( 'That poster image type is not supported.', 'oc-story' ), array( 'status' => 415 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = sanitize_file_name( ( '' !== $title ? $title : 'story' ) . '-poster.' . $ext );
		$tmp      = wp_tempnam( $filename );

		if ( ! $tmp ) {
			return new \WP_Error( 'ocs_poster_tmp', __( 'The server could not store the poster image.', 'oc-story' ), array( 'status' => 500 ) );
		}

		file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// The bytes claim to be an image; check that they are one, and that the
		// extension we are about to write matches what they actually contain.
		$check = wp_check_filetype_and_ext( $tmp, $filename, self::MIMES );
		if ( empty( $check['type'] ) || $check['type'] !== $mime ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'ocs_poster_invalid', __( 'That poster image could not be read.', 'oc-story' ), array( 'status' => 415 ) );
		}

		$sideload = wp_handle_sideload(
			array(
				'name'     => $filename,
				'type'     => $mime,
				'tmp_name' => $tmp,
				'error'    => 0,
				'size'     => strlen( $bytes ),
			),
			array(
				'test_form' => false,
				'action'    => 'ocs_upload',
				'mimes'     => self::MIMES,
			)
		);

		if ( isset( $sideload['error'] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'ocs_poster_sideload', (string) $sideload['error'], array( 'status' => 500 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $sideload['type'],
				'post_title'     => '' !== $title ? $title : __( 'Story poster', 'oc-story' ),
				'post_status'    => 'inherit',
			),
			$sideload['file']
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $sideload['file'] );
			return new \WP_Error( 'ocs_poster_attach', __( 'The poster could not be added to the media library.', 'oc-story' ), array( 'status' => 500 ) );
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $sideload['file'] )
		);

		if ( $video_id ) {
			self::link( $attachment_id, (int) $video_id );
		}

		return (int) $attachment_id;
	}

	/**
	 * Record which video a poster belongs to.
	 *
	 * Without this, deleting a story orphans the poster in the media library and
	 * nobody ever finds it again.
	 *
	 * @param int $poster_id Poster attachment ID.
	 * @param int $video_id  Video attachment ID.
	 */
	public static function link( $poster_id, $video_id ) {
		update_post_meta( (int) $poster_id, self::META_FOR, (int) $video_id );
	}

	/**
	 * Decode a `data:` URL into bytes and a mime type.
	 *
	 * The studio sends the canvas export as a data URL because that is what
	 * `toDataURL` produces and it survives a JSON body without a second request.
	 *
	 * Pure string handling — the harness pins the malformed cases down.
	 *
	 * @param string $data_url Data URL.
	 * @return array{mime:string,bytes:string}|null
	 */
	public static function decode_data_url( $data_url ) {
		$data_url = (string) $data_url;

		if ( ! preg_match( '#^data:([a-z]+/[a-z0-9.+-]+);base64,#i', $data_url, $m ) ) {
			return null;
		}

		$mime    = strtolower( $m[1] );
		$encoded = substr( $data_url, strlen( $m[0] ) );

		if ( '' === $encoded ) {
			return null;
		}

		$bytes = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return null;
		}

		return array(
			'mime'  => $mime,
			'bytes' => $bytes,
		);
	}
}
