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

		$out = array();
		foreach ( $posts as $post ) {
			$story = Story::to_array( $post );
			if ( $story ) {
				$out[] = $story;
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
				'title'  => (string) $request['title'],
				'status' => (string) $request['status'],
				'slides' => is_array( $request['slides'] ) ? $request['slides'] : array(),
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		do_action( 'ocs_story_published', $id );

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

		foreach ( array( 'title', 'status', 'slides', 'poster' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$args[ $key ] = $request[ $key ];
			}
		}

		$result = Story::update( (int) $request['id'], $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

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

		return rest_ensure_response( array( 'moved' => $moved ) );
	}
}
