<?php
/**
 * What this server can actually do.
 *
 * @package OC_Story
 */

namespace OCS\Media;

use OCS\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the host's real limits so the upload can be shaped to fit them.
 *
 * The whole point of chunking is that we never hand PHP a request larger than it
 * accepts. That means asking, not assuming: shared hosts run anywhere from 2MB
 * to 512MB, and the studio has to work on all of them without a support ticket.
 */
class Probe {

	const FFMPEG_TRANSIENT = 'ocs_ffmpeg';

	/**
	 * Parse a php.ini shorthand size into bytes.
	 *
	 * Returns 0 for "unlimited" (-1) and for anything unparseable, which callers
	 * treat as "no opinion" rather than "no space".
	 *
	 * @param string|int $value Raw ini value, e.g. "8M".
	 * @return int
	 */
	public static function ini_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		if ( '-1' === $value ) {
			return 0;
		}

		if ( ! preg_match( '/^(\d+(?:\.\d+)?)\s*([kmgt]?)b?$/i', $value, $m ) ) {
			return 0;
		}

		$bytes = (float) $m[1];

		switch ( strtolower( $m[2] ) ) {
			case 't':
				$bytes *= 1024;
				// Fall through.
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
				break;
		}

		return (int) $bytes;
	}

	/**
	 * The largest request body this server will accept.
	 *
	 * @return int Bytes, 0 when nothing limits us.
	 */
	public static function max_request_bytes() {
		$limits = array_filter(
			array(
				self::ini_bytes( ini_get( 'upload_max_filesize' ) ),
				self::ini_bytes( ini_get( 'post_max_size' ) ),
			)
		);

		return $limits ? (int) min( $limits ) : 0;
	}

	/**
	 * Chunk size to use for an upload.
	 *
	 * Stays comfortably under the smaller of the two PHP limits, because
	 * `post_max_size` counts the whole request and not just the file. Floored at
	 * 256KB so a hostile-but-legal 1MB `post_max_size` does not turn a 9MB video
	 * into a thousand requests, and capped at 8MB because larger chunks buy
	 * nothing and cost more on a retry.
	 *
	 * Pure arithmetic, so the harness can pin the edge cases down.
	 *
	 * @param int $server_max Server maximum in bytes, 0 for unlimited.
	 * @param int $preferred  Preferred size in bytes.
	 * @return int
	 */
	public static function chunk_size_for( $server_max, $preferred ) {
		$min = 262144;   // 256KB.
		$max = 8388608;  // 8MB.

		$size = (int) $preferred > 0 ? (int) $preferred : 2097152;

		if ( (int) $server_max > 0 ) {
			// 80% leaves room for the multipart envelope and the other fields.
			$size = (int) min( $size, floor( (int) $server_max * 0.8 ) );
		}

		if ( $size > $max ) {
			$size = $max;
		}
		if ( $size < $min ) {
			$size = $min;
		}

		return $size;
	}

	/**
	 * Chunk size for this server, with the setting applied.
	 *
	 * @return int
	 */
	public static function chunk_size() {
		$size = self::chunk_size_for(
			self::max_request_bytes(),
			(int) Settings::get( 'chunk_size_bytes', 2097152 )
		);

		return (int) apply_filters( 'ocs_upload_chunk_size', $size );
	}

	/**
	 * Video mime types we accept.
	 *
	 * The studio always produces MP4. MOV and WebM are here for the fallback
	 * path, where the device could not re-encode and uploads what it has.
	 *
	 * @return array<string,string> Extension pattern => mime.
	 */
	public static function allowed_mimes() {
		return (array) apply_filters(
			'ocs_allowed_video_mimes',
			array(
				'mp4'  => 'video/mp4',
				'm4v'  => 'video/mp4',
				'mov'  => 'video/quicktime',
				'webm' => 'video/webm',
			)
		);
	}

	/**
	 * Is a server-side ffmpeg available?
	 *
	 * Only ever a fallback — see PLAN.md §12. Cached for a day because probing
	 * shells out, and the answer changes when someone changes the server, which
	 * is not something that happens between two page loads.
	 *
	 * @return bool
	 */
	public static function has_ffmpeg() {
		$cached = get_transient( self::FFMPEG_TRANSIENT );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		$available = 'no';

		if ( self::can_exec() ) {
			$output = array();
			$status = 1;
			@exec( 'ffmpeg -version 2>/dev/null', $output, $status ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			if ( 0 === (int) $status && ! empty( $output ) ) {
				$available = 'yes';
			}
		}

		set_transient( self::FFMPEG_TRANSIENT, $available, DAY_IN_SECONDS );

		return 'yes' === $available;
	}

	/**
	 * Is exec() usable at all? Most managed hosts disable it.
	 *
	 * @return bool
	 */
	protected static function can_exec() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'exec', $disabled, true );
	}
}
