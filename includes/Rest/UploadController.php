<?php
/**
 * Upload routes.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

use OCS\Core\Settings;
use OCS\Media\ChunkedUpload;
use OCS\Media\Probe;

defined( 'ABSPATH' ) || exit;

/**
 * Init, chunk, complete, abort — plus the limits the studio needs before it
 * starts, so the browser never has to guess what this server will accept.
 */
class UploadController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = Routes::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/admin/upload/limits',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'limits' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/admin/upload/init',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'init' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(
					'filename' => array(
						'type'     => 'string',
						'required' => true,
					),
					'size'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'mime'     => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		// The chunk body is raw bytes, not JSON: the session and index travel in
		// the query string so the body is never copied or re-encoded.
		register_rest_route(
			$ns,
			'/admin/upload/chunk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'chunk' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(
					'session' => array(
						'type'     => 'string',
						'required' => true,
					),
					'index'   => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/upload/complete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(
					'session'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'w'        => array( 'type' => 'integer' ),
					'h'        => array( 'type' => 'integer' ),
					'duration' => array( 'type' => 'number' ),
					'poster'   => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'Poster frame as a data: URL.',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/upload/abort',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'abort' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(
					'session' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * What this server accepts, and what the studio should encode to.
	 *
	 * @return \WP_REST_Response
	 */
	public function limits() {
		return rest_ensure_response(
			array(
				'chunk_size'   => Probe::chunk_size(),
				'max_bytes'    => max( 1, (int) Settings::get( 'max_upload_mb', 200 ) ) * MB_IN_BYTES,
				'max_seconds'  => (int) Settings::get( 'max_slide_seconds', 60 ),
				'mimes'        => array_values( Probe::allowed_mimes() ),
				'server_max'   => Probe::max_request_bytes(),
				'has_ffmpeg'   => Probe::has_ffmpeg(),
				'encode'       => array(
					'enabled' => Settings::is( 'encode_enabled' ),
					'max_side' => (int) Settings::get( 'max_long_side', 1280 ),
					'bitrate' => (int) Settings::get( 'target_bitrate', 1500000 ),
					'fps'     => (int) Settings::get( 'target_fps', 30 ),
					'audio'   => (int) Settings::get( 'audio_bitrate', 96000 ),
				),
			)
		);
	}

	/**
	 * Open a session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function init( $request ) {
		$result = ChunkedUpload::init(
			array(
				'filename' => (string) $request['filename'],
				'mime'     => (string) $request['mime'],
				'size'     => (int) $request['size'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Store one chunk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function chunk( $request ) {
		$result = ChunkedUpload::receive(
			(string) $request['session'],
			(int) $request['index'],
			(string) $request->get_body()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Finish a session, and store the poster alongside the video.
	 *
	 * A failed poster does not fail the upload. The video is already in the
	 * library at that point, and a story with a missing poster is a fixable
	 * problem where a lost video is not.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function complete( $request ) {
		$result = ChunkedUpload::finish(
			(string) $request['session'],
			array(
				'w'        => (int) $request['w'],
				'h'        => (int) $request['h'],
				'duration' => (float) $request['duration'],
				'poster'   => (string) $request['poster'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Cancel a session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function abort( $request ) {
		$result = ChunkedUpload::abort( (string) $request['session'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'aborted' => true ) );
	}
}
