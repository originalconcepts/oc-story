<?php
/**
 * Story routes.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

use OCS\Model\Story;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the studio does to a story.
 */
class StoriesController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = Routes::NAMESPACE_V1;

		// Public, no nonce, safe to serve from a cache. It is only reached when
		// a bar was too large to inline, and it exposes exactly what that bar
		// would have carried in its own HTML anyway.
		register_rest_route(
			$ns,
			'/stories',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'payload' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/stories',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( Routes::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( Routes::class, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/stories/reorder',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reorder' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/stories/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( Routes::class, 'can_manage' ),
				),
				array(
					'methods'             => 'PATCH, PUT, POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( Routes::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => array( Routes::class, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * The player payload for a set of published stories.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function payload( $request ) {
		$ids = array_slice(
			array_values( array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) ) ),
			0,
			50
		);

		if ( ! $ids ) {
			return rest_ensure_response( array() );
		}

		// The plugin version belongs in the key: the payload's shape changes
		// with the code, not with the content.
		$key    = 'ocs_payload_' . md5( implode( ',', $ids ) . '|' . Story::version() . '|' . OCS_VERSION );
		$cached = get_transient( $key );

		if ( ! is_array( $cached ) ) {
			$posts  = Story::published(
				array(
					'limit'   => count( $ids ),
					'include' => $ids,
				)
			);
			$cached = $posts ? Story::to_payload( Story::to_array_many( $posts ) ) : array();

			set_transient( $key, $cached, 12 * HOUR_IN_SECONDS );
		}

		$response = rest_ensure_response( $cached );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Every story, in bar order, drafts included.
	 *
	 * @return \WP_REST_Response
	 */
	public function index() {
		$posts = get_posts(
			array(
				'post_type'              => Story::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => 200,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$opens = \OCS\Model\Stats::opens_last_week();

		$out = array();
		foreach ( $posts as $post ) {
			$story = Story::to_array( $post );
			if ( $story ) {
				$story['views7d'] = isset( $opens[ $story['id'] ] ) ? $opens[ $story['id'] ] : 0;
				$out[]            = $story;
			}
		}

		return rest_ensure_response( $out );
	}

	/**
	 * One story.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( $request ) {
		$story = Story::to_array( (int) $request['id'] );

		if ( ! $story ) {
			return new \WP_Error( 'ocs_not_found', __( 'That story does not exist.', 'oc-story' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $story );
	}

	/**
	 * Create a story.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( $request ) {
		$id = Story::create(
			array(
				'title'      => (string) $request['title'],
				'status'     => (string) $request['status'],
				'collection' => (string) $request['collection'],
				'slides'     => is_array( $request['slides'] ) ? $request['slides'] : array(),
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		do_action( 'ocs_story_published', $id );

		\OCS\Core\CacheFlush::pages();

		return rest_ensure_response( Story::to_array( $id ) );
	}

	/**
	 * Update a story.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( $request ) {
		$args = array();

		foreach ( array( 'title', 'status', 'slides', 'poster', 'collection' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$args[ $key ] = $request[ $key ];
			}
		}

		$result = Story::update( (int) $request['id'], $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		\OCS\Core\CacheFlush::pages();

		return rest_ensure_response( Story::to_array( $result ) );
	}

	/**
	 * Delete a story.
	 *
	 * The videos and posters stay in the media library. They are the shop's own
	 * footage and may be used elsewhere; removing a circle from a bar should not
	 * destroy the recording behind it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function destroy( $request ) {
		$id = (int) $request['id'];

		if ( Story::POST_TYPE !== get_post_type( $id ) ) {
			return new \WP_Error( 'ocs_not_found', __( 'That story does not exist.', 'oc-story' ), array( 'status' => 404 ) );
		}

		wp_delete_post( $id, true );

		\OCS\Core\CacheFlush::pages();

		return rest_ensure_response( array( 'deleted' => $id ) );
	}

	/**
	 * Reorder the bar.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reorder( $request ) {
		$moved = Story::reorder( (array) $request['ids'] );

		\OCS\Core\CacheFlush::pages();

		return rest_ensure_response( array( 'moved' => $moved ) );
	}
}
