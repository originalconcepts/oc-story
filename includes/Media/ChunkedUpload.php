<?php
/**
 * Resumable chunked uploads.
 *
 * @package OC_Story
 */

namespace OCS\Media;

use OCS\Core\Install;
use OCS\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads arrive in pieces, and no piece is ever larger than what PHP accepts.
 *
 * Two problems solved by the same mechanism. The first is `upload_max_filesize`:
 * a common shared host caps a request at 8MB, and a single-request upload of a
 * 40MB fallback video simply cannot succeed there. The second is the phone: a
 * mobile connection drops, and re-sending 2MB is recoverable where re-sending
 * the whole file is not.
 *
 * Chunks may arrive in any order and more than once — each is written at its own
 * offset and marked in a bitmap beside the file, so a retry is idempotent and
 * costs no database write. The row is only touched at init and at completion.
 */
class ChunkedUpload {

	const DIR         = 'oc-story-tmp';
	const TTL_SECONDS = 6 * HOUR_IN_SECONDS;
	const MAX_CHUNKS  = 4096;

	/**
	 * Open a session.
	 *
	 * @param array $args Keys: filename, mime, size.
	 * @return array|\WP_Error
	 */
	public static function init( array $args ) {
		$filename = isset( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';
		$mime     = isset( $args['mime'] ) ? strtolower( (string) $args['mime'] ) : '';
		$size     = isset( $args['size'] ) ? (int) $args['size'] : 0;

		if ( '' === $filename ) {
			$filename = 'story.mp4';
		}

		if ( $size < 1 ) {
			return new \WP_Error( 'ocs_bad_size', __( 'The file size is missing.', 'oc-story' ), array( 'status' => 400 ) );
		}

		$ceiling = max( 1, (int) Settings::get( 'max_upload_mb', 200 ) ) * MB_IN_BYTES;
		if ( $size > $ceiling ) {
			return new \WP_Error(
				'ocs_too_large',
				sprintf(
					/* translators: %d: size limit in megabytes */
					__( 'That video is larger than the %dMB limit. Trim it or lower the quality and try again.', 'oc-story' ),
					(int) Settings::get( 'max_upload_mb', 200 )
				),
				array( 'status' => 413 )
			);
		}

		if ( '' !== $mime && ! in_array( $mime, array_values( Probe::allowed_mimes() ), true ) ) {
			return new \WP_Error( 'ocs_bad_mime', __( 'That file type is not a video we can use.', 'oc-story' ), array( 'status' => 415 ) );
		}

		$dir = self::directory();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$chunk_size   = Probe::chunk_size();
		$chunks_total = (int) ceil( $size / $chunk_size );

		if ( $chunks_total > self::MAX_CHUNKS ) {
			return new \WP_Error( 'ocs_too_many_chunks', __( 'That video is too large to upload from this server.', 'oc-story' ), array( 'status' => 413 ) );
		}

		$session  = self::new_session();
		$tmp_path = trailingslashit( $dir ) . $session . '.part';

		// Reserve both files immediately, so a session always has somewhere to
		// write and a half-open session is visible to the sweeper.
		if ( false === file_put_contents( $tmp_path, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'ocs_tmp_write', __( 'The server could not create a temporary file for the upload.', 'oc-story' ), array( 'status' => 500 ) );
		}
		file_put_contents( self::map_path( $tmp_path ), str_repeat( '0', $chunks_total ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			Install::table( 'uploads' ),
			array(
				'session'      => $session,
				'user_id'      => get_current_user_id(),
				'filename'     => $filename,
				'mime'         => $mime,
				'size'         => $size,
				'chunk_size'   => $chunk_size,
				'chunks_total' => $chunks_total,
				'chunks_done'  => 0,
				'tmp_path'     => $tmp_path,
				'created_at'   => $now,
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wp_delete_file( $tmp_path );
			wp_delete_file( self::map_path( $tmp_path ) );
			return new \WP_Error( 'ocs_session', __( 'The upload could not be started.', 'oc-story' ), array( 'status' => 500 ) );
		}

		return array(
			'session'      => $session,
			'chunk_size'   => $chunk_size,
			'chunks_total' => $chunks_total,
		);
	}

	/**
	 * Store one chunk.
	 *
	 * @param string $session Session id.
	 * @param int    $index   Zero-based chunk index.
	 * @param string $body    Raw chunk bytes.
	 * @return array|\WP_Error
	 */
	public static function receive( $session, $index, $body ) {
		$row = self::session( $session );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$index  = (int) $index;
		$length = strlen( $body );

		if ( $index < 0 || $index >= (int) $row['chunks_total'] ) {
			return new \WP_Error( 'ocs_bad_index', __( 'That chunk is outside the upload.', 'oc-story' ), array( 'status' => 400 ) );
		}

		if ( $length < 1 ) {
			return new \WP_Error( 'ocs_empty_chunk', __( 'The chunk was empty.', 'oc-story' ), array( 'status' => 400 ) );
		}

		$expected = self::expected_chunk_length( $index, (int) $row['chunk_size'], (int) $row['size'] );
		if ( $length !== $expected ) {
			return new \WP_Error(
				'ocs_chunk_length',
				__( 'That chunk was not the size we expected. Retry it.', 'oc-story' ),
				array( 'status' => 400 )
			);
		}

		$handle = fopen( $row['tmp_path'], 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new \WP_Error( 'ocs_tmp_open', __( 'The server could not write the upload.', 'oc-story' ), array( 'status' => 500 ) );
		}

		fseek( $handle, $index * (int) $row['chunk_size'] );
		$written = fwrite( $handle, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $written !== $length ) {
			return new \WP_Error( 'ocs_tmp_write', __( 'The chunk was not written in full. Retry it.', 'oc-story' ), array( 'status' => 500 ) );
		}

		self::mark( $row['tmp_path'], $index );

		$map = self::map( $row['tmp_path'] );

		return array(
			'received'     => substr_count( $map, '1' ),
			'chunks_total' => (int) $row['chunks_total'],
		);
	}

	/**
	 * Finish a session: verify, hand to the storage source, clean up.
	 *
	 * @param string $session Session id.
	 * @param array  $meta    Keys: w, h, duration.
	 * @return array|\WP_Error
	 */
	public static function complete( $session, array $meta = array() ) {
		$row = self::session( $session );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$map = self::map( $row['tmp_path'] );
		if ( false !== strpos( $map, '0' ) ) {
			return new \WP_Error(
				'ocs_incomplete',
				__( 'Some of the video did not arrive. Retry the missing pieces.', 'oc-story' ),
				array(
					'status'  => 409,
					'missing' => self::missing( $map ),
				)
			);
		}

		// A correct bitmap and a wrong size means something wrote outside the
		// protocol. Refuse rather than store it.
		if ( (int) filesize( $row['tmp_path'] ) !== (int) $row['size'] ) {
			self::discard( $row );
			return new \WP_Error( 'ocs_size_mismatch', __( 'The upload did not match its declared size.', 'oc-story' ), array( 'status' => 409 ) );
		}

		$check = wp_check_filetype_and_ext( $row['tmp_path'], $row['filename'], Probe::allowed_mimes() );
		if ( empty( $check['type'] ) ) {
			self::discard( $row );
			return new \WP_Error( 'ocs_bad_file', __( 'That file is not a video we can use.', 'oc-story' ), array( 'status' => 415 ) );
		}

		$source = SourceManager::active();
		$ref    = $source->ingest(
			$row['tmp_path'],
			array(
				'filename' => $row['filename'],
				'mime'     => $check['type'],
				'w'        => isset( $meta['w'] ) ? (int) $meta['w'] : 0,
				'h'        => isset( $meta['h'] ) ? (int) $meta['h'] : 0,
				'duration' => isset( $meta['duration'] ) ? (float) $meta['duration'] : 0.0,
			)
		);

		// ingest() moves the file, so only the bitmap and the row are left.
		self::forget( $row );

		if ( is_wp_error( $ref ) ) {
			return $ref;
		}

		$result = array(
			'source'   => $source->get_id(),
			'ref'      => (string) $ref,
			'url'      => $source->get_playback_url( $ref ),
			'w'        => isset( $meta['w'] ) ? (int) $meta['w'] : 0,
			'h'        => isset( $meta['h'] ) ? (int) $meta['h'] : 0,
			'duration' => isset( $meta['duration'] ) ? round( (float) $meta['duration'], 2 ) : 0.0,
		);

		do_action( 'ocs_upload_complete', $result );

		return $result;
	}

	/**
	 * Cancel a session and remove its files.
	 *
	 * @param string $session Session id.
	 * @return bool|\WP_Error
	 */
	public static function abort( $session ) {
		$row = self::session( $session );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		self::discard( $row );

		return true;
	}

	/**
	 * How long chunk $index should be.
	 *
	 * The last chunk is the remainder; every other chunk is exactly the chunk
	 * size. Checking this is what stops a client silently truncating a chunk and
	 * leaving a file that is the right length but full of holes.
	 *
	 * Pure arithmetic — pinned down by the harness.
	 *
	 * @param int $index      Chunk index.
	 * @param int $chunk_size Chunk size in bytes.
	 * @param int $total      Total file size in bytes.
	 * @return int
	 */
	public static function expected_chunk_length( $index, $chunk_size, $total ) {
		$index      = (int) $index;
		$chunk_size = (int) $chunk_size;
		$total      = (int) $total;

		if ( $chunk_size < 1 || $total < 1 || $index < 0 ) {
			return 0;
		}

		$offset = $index * $chunk_size;
		if ( $offset >= $total ) {
			return 0;
		}

		$remaining = $total - $offset;

		return $remaining < $chunk_size ? $remaining : $chunk_size;
	}

	/**
	 * Indexes still missing from a bitmap.
	 *
	 * @param string $map Bitmap.
	 * @return int[]
	 */
	public static function missing( $map ) {
		$out    = array();
		$length = strlen( $map );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( '1' !== $map[ $i ] ) {
				$out[] = $i;
			}
		}

		return $out;
	}

	/**
	 * Load a session row, scoped to the current user.
	 *
	 * @param string $session Session id.
	 * @return array|\WP_Error
	 */
	protected static function session( $session ) {
		global $wpdb;

		$session = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $session ) );
		if ( 32 !== strlen( $session ) ) {
			return new \WP_Error( 'ocs_bad_session', __( 'That upload session is not valid.', 'oc-story' ), array( 'status' => 400 ) );
		}

		$table = Install::table( 'uploads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session = %s", $session ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new \WP_Error( 'ocs_no_session', __( 'That upload session has expired.', 'oc-story' ), array( 'status' => 404 ) );
		}

		// A session belongs to the person who opened it. Two shop managers
		// uploading at once must not be able to write into each other's file.
		if ( (int) $row['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'ocs_not_yours', __( 'That upload belongs to someone else.', 'oc-story' ), array( 'status' => 403 ) );
		}

		if ( ! is_file( $row['tmp_path'] ) ) {
			return new \WP_Error( 'ocs_no_tmp', __( 'The partial upload is gone. Start again.', 'oc-story' ), array( 'status' => 410 ) );
		}

		return $row;
	}

	/**
	 * Bitmap path for a temp file.
	 *
	 * @param string $tmp_path Temp file path.
	 * @return string
	 */
	protected static function map_path( $tmp_path ) {
		return $tmp_path . '.map';
	}

	/**
	 * Read the bitmap.
	 *
	 * @param string $tmp_path Temp file path.
	 * @return string
	 */
	protected static function map( $tmp_path ) {
		$path = self::map_path( $tmp_path );
		if ( ! is_file( $path ) ) {
			return '';
		}
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	}

	/**
	 * Mark one chunk as received.
	 *
	 * A single byte written at a fixed offset: idempotent under a retry, and
	 * safe enough under two concurrent chunks that no lock is worth its cost.
	 *
	 * @param string $tmp_path Temp file path.
	 * @param int    $index    Chunk index.
	 */
	protected static function mark( $tmp_path, $index ) {
		$handle = fopen( self::map_path( $tmp_path ), 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return;
		}
		fseek( $handle, (int) $index );
		fwrite( $handle, '1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Delete the row and the bitmap, leaving the video alone.
	 *
	 * @param array $row Session row.
	 */
	protected static function forget( array $row ) {
		global $wpdb;

		$map = self::map_path( $row['tmp_path'] );
		if ( is_file( $map ) ) {
			wp_delete_file( $map );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Install::table( 'uploads' ), array( 'id' => (int) $row['id'] ), array( '%d' ) );
	}

	/**
	 * Delete the row, the bitmap and the partial file.
	 *
	 * @param array $row Session row.
	 */
	protected static function discard( array $row ) {
		if ( is_file( $row['tmp_path'] ) ) {
			wp_delete_file( $row['tmp_path'] );
		}
		self::forget( $row );
	}

	/**
	 * A new session id.
	 *
	 * @return string
	 */
	protected static function new_session() {
		return md5( uniqid( 'ocs', true ) . wp_generate_password( 12, false ) );
	}

	/**
	 * The protected temp directory, created on demand.
	 *
	 * Partial uploads are unvalidated bytes with a video extension. They live
	 * under uploads because that is the only reliably writable place on a
	 * WordPress host, and they are locked down because that directory is served.
	 *
	 * @return string|\WP_Error
	 */
	protected static function directory() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'ocs_uploads_dir', (string) $uploads['error'], array( 'status' => 500 ) );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'ocs_uploads_dir', __( 'The server could not create the upload directory.', 'oc-story' ), array( 'status' => 500 ) );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! is_file( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! is_file( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}
}
