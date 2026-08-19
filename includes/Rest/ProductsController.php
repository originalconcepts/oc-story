<?php
/**
 * Product search for the studio.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

use OCS\Model\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Tagging a product is the one step in the studio that has to feel instant, so
 * this route does one thing and returns five fields.
 */
class ProductsController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			Routes::NAMESPACE_V1,
			'/admin/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(
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
	 * Search, or resolve a known set of IDs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search( $request ) {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) );

		if ( $ids ) {
			return rest_ensure_response( array_values( Products::summaries( $ids ) ) );
		}

		return rest_ensure_response( Products::search( (string) $request['search'] ) );
	}
}
