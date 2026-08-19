<?php
/**
 * Page, product and category lookup for the placements screen.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

use OCS\Model\Products;

defined( 'ABSPATH' ) || exit;

/**
 * "Only the pages I choose" is only usable if choosing a page means typing its
 * name. This is the search behind that.
 */
class LookupController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			Routes::NAMESPACE_V1,
			'/admin/lookup',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(
					'type'   => array(
						'type'    => 'string',
						'default' => 'page',
					),
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
					'ids'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Search, or resolve known ids so a saved placement can show names.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search( $request ) {
		$type   = (string) $request['type'];
		$term   = (string) $request['search'];
		$ids    = array_values( array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) ) );
		$result = array();

		if ( 'term' === $type ) {
			$args = array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 20,
			);

			if ( $ids ) {
				$args['include'] = $ids;
			} elseif ( '' !== $term ) {
				$args['search'] = $term;
			}

			foreach ( (array) get_terms( $args ) as $found ) {
				if ( $found instanceof \WP_Term ) {
					$result[] = array(
						'id'   => $found->term_id,
						'name' => $found->name,
					);
				}
			}

			return rest_ensure_response( $result );
		}

		if ( 'product' === $type ) {
			$products = $ids ? array_values( Products::summaries( $ids ) ) : Products::search( $term );

			foreach ( $products as $product ) {
				$result[] = array(
					'id'   => $product['id'],
					'name' => $product['name'],
				);
			}

			return rest_ensure_response( $result );
		}

		$args = array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'no_found_rows'  => true,
		);

		if ( $ids ) {
			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		} else {
			$args['s'] = $term;
		}

		foreach ( get_posts( $args ) as $post ) {
			$result[] = array(
				'id'   => $post->ID,
				'name' => $post->post_title ? $post->post_title : __( '(no title)', 'oc-story' ),
			);
		}

		return rest_ensure_response( $result );
	}
}
