<?php
/**
 * The door a share link opens. Not the admin one.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

use OCS\Core\Settings;
use OCS\Media\ChunkedUpload;
use OCS\Media\Probe;
use OCS\Model\Placement;
use OCS\Model\Products;
use OCS\Model\ShareLink;
use OCS\Model\Story;

defined( 'ABSPATH' ) || exit;

/**
 * Every route here is reachable by anyone holding a link, so every route here
 * is written as if a stranger were holding it.
 *
 * The rules, in one place:
 *
 *   No route consults `can_manage`, a cookie or a nonce. The token and the
 *   device secret are the whole of the authority, and they are checked on
 *   every single request rather than once at the start of a session.
 *
 *   Nothing here deletes, edits another gallery, or reads a setting, an
 *   order or a customer. The verbs are: look at this gallery, search
 *   products by name, upload a file, add a video. That is all of them.
 *
 *   The upload runs through exactly the same `ChunkedUpload` the admin uses,
 *   with the same size and type checks. A second path would be a second set
 *   of mistakes.
 */
class ShareController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = Routes::NAMESPACE_V1;

		$open = array(
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);

		register_rest_route(
			$ns,
			'/share/claim',
			array_merge(
				$open,
				array(
					'methods'  => \WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'claim' ),
				)
			)
		);

		register_rest_route(
			$ns,
			'/share/gallery',
			array_merge(
				$open,
				array(
					'methods'  => \WP_REST_Server::READABLE,
					'callback' => array( $this, 'gallery' ),
				)
			)
		);

		register_rest_route(
			$ns,
			'/share/products',
			array_merge(
				$open,
				array(
					'methods'  => \WP_REST_Server::READABLE,
					'callback' => array( $this, 'products' ),
				)
			)
		);

		foreach ( array( 'init', 'chunk', 'complete', 'abort' ) as $step ) {
			register_rest_route(
				$ns,
				'/share/upload/' . $step,
				array_merge(
					$open,
					array(
						'methods'  => \WP_REST_Server::CREATABLE,
						'callback' => array( $this, 'upload_' . $step ),
					)
				)
			);
		}

		register_rest_route(
			$ns,
			'/share/video',
			array_merge(
				$open,
				array(
					'methods'  => \WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'add_video' ),
				)
			)
		);
	}

	/**
	 * Bind this link to the device asking, once.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function claim( $request ) {
		$secret = ShareLink::claim( (string) $request['token'] );

		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		return rest_ensure_response( array( 'device' => $secret ) );
	}

	/**
	 * What this link is for.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function gallery( $request ) {
		$found = $this->open( $request );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$placement = Placement::get( $found['id'] );

		if ( ! $placement ) {
			return new \WP_Error( 'ocs_gone', __( 'That gallery no longer exists.', 'oc-story' ), array( 'status' => 404 ) );
		}

		// A story gallery holds several stories and a new video can join one
		// of them, so their names come along. Nothing else about the shop
		// does.
		$stories = array();

		if ( 'circles' === $placement['surface'] ) {
			foreach ( (array) $placement['stories']['ids'] as $story_id ) {
				$story = Story::to_array( (int) $story_id );

				if ( $story ) {
					$stories[] = array(
						'id'     => $story['id'],
						'title'  => $story['title'],
						'thumb'  => $story['thumb_url'],
						'slides' => count( $story['slides'] ),
					);
				}
			}
		}

		ShareLink::touch( $found['id'] );

		return rest_ensure_response(
			array(
				'gallery' => array(
					'label'   => $placement['label'],
					'surface' => $placement['surface'],
					'series'  => 'circles' === $placement['surface'],
				),
				'stories' => $stories,
				'hold'    => ! empty( $found['link']['hold'] ),
				'limits'  => $this->limits(),
			)
		);
	}

	/**
	 * The same numbers the admin uploader is given.
	 *
	 * @return array
	 */
	protected function limits() {
		return array(
			'chunk_size'  => Probe::chunk_size(),
			'max_bytes'   => max( 1, (int) Settings::get( 'max_upload_mb', 200 ) ) * MB_IN_BYTES,
			'max_seconds' => (int) Settings::get( 'max_slide_seconds', 60 ),
			'mimes'       => array_values( Probe::allowed_mimes() ),
			'server_max'  => Probe::max_request_bytes(),
			'encode'      => array(
				'enabled'  => Settings::is( 'encode_enabled' ),
				'max_side' => (int) Settings::get( 'max_long_side', 1280 ),
				'bitrate'  => (int) Settings::get( 'target_bitrate', 1500000 ),
				'fps'      => (int) Settings::get( 'target_fps', 30 ),
				'audio'    => (int) Settings::get( 'audio_bitrate', 96000 ),
			),
		);
	}

	/**
	 * Search products to tag, by name.
	 *
	 * Product names and prices are on every shelf of the shop already; this
	 * exposes nothing a visitor could not read from the catalogue.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function products( $request ) {
		$found = $this->open( $request );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		return rest_ensure_response( Products::search( (string) $request['search'] ) );
	}

	/**
	 * Start an upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_init( $request ) {
		$found = $this->open( $request );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		return rest_ensure_response(
			ChunkedUpload::init(
				array(
					'filename' => (string) $request['filename'],
					'mime'     => (string) $request['mime'],
					'size'     => (int) $request['size'],
				)
			)
		);
	}

	/**
	 * Take one chunk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_chunk( $request ) {
		$found = $this->open( $request );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		return rest_ensure_response(
			ChunkedUpload::receive(
				(string) $request['session'],
				(int) $request['index'],
				$request->get_body()
			)
		);
	}

	/**
	 * Finish an upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_complete( $request ) {
		$found = $this->open( $request );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		return rest_ensure_response(
			ChunkedUpload::complete( (string) $request['session'], (array) $request['meta'] )
		);
	}

	/**
	 * Throw an upload away.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_abort( $request ) {
		$found = $this->open( $request );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		return rest_ensure_response( ChunkedUpload::abort( (string) $request['session'] ) );
	}

	/**
	 * Put a finished video into this gallery.
	 *
	 * The only thing this door writes, and it writes it in one shape: a new
	 * slide, on a story belonging to this gallery, and nothing else about
	 * that story or that gallery is touched.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_video( $request ) {
		$found = $this->open( $request );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$placement = Placement::get( $found['id'] );

		if ( ! $placement ) {
			return new \WP_Error( 'ocs_gone', __( 'That gallery no longer exists.', 'oc-story' ), array( 'status' => 404 ) );
		}

		$slide = Story::normalize_slides( array( (array) $request['slide'] ) );

		if ( ! $slide ) {
			return new \WP_Error( 'ocs_bad_slide', __( 'That video did not arrive in one piece. Try again.', 'oc-story' ), array( 'status' => 400 ) );
		}

		$hold   = ! empty( $found['link']['hold'] );
		$status = $hold ? 'draft' : 'publish';

		// Joining a story this gallery already shows, or starting a new one.
		$join = (int) $request['story'];

		if ( $join && in_array( $join, array_map( 'absint', (array) $placement['stories']['ids'] ), true ) ) {
			$slides = Story::slides( $join );
			$slides[] = $slide[0];

			Story::set_slides( $join, $slides );
			ShareLink::touch( $found['id'], true );

			return rest_ensure_response( array( 'story' => $join, 'held' => $hold ) );
		}

		$story_id = Story::create(
			array(
				'title'  => (string) $request['title'],
				'status' => $status,
				'slides' => $slide,
			)
		);

		if ( is_wp_error( $story_id ) ) {
			return $story_id;
		}

		// The gallery gains the video. This is a write to the placement, so
		// it is done by hand rather than through the admin's replace-all
		// endpoint: one id appended, nothing else in the option touched.
		$all = Placement::all();

		if ( isset( $all[ $found['id'] ] ) ) {
			$ids   = array_map( 'absint', (array) $all[ $found['id'] ]['stories']['ids'] );
			$ids[] = (int) $story_id;

			$all[ $found['id'] ]['stories']['ids']  = array_values( array_unique( $ids ) );
			$all[ $found['id'] ]['stories']['mode'] = 'tagged' === $all[ $found['id'] ]['stories']['mode']
				? 'tagged'
				: 'selected';

			update_option( Placement::OPTION, array_values( $all ), true );
			Placement::flush();
		}

		Story::bump_version();
		\OCS\Core\CacheFlush::pages();
		ShareLink::touch( $found['id'], true );

		return rest_ensure_response( array( 'story' => (int) $story_id, 'held' => $hold ) );
	}

	/**
	 * The check every route above begins with.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	protected function open( $request ) {
		return ShareLink::resolve(
			(string) $request['token'],
			(string) $request->get_header( 'x-ocs-device' )
		);
	}
}
