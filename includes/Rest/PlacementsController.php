<?php
/**
 * Placement routes.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

use OCS\Model\Placement;
use OCS\Surfaces\SurfaceManager;

defined( 'ABSPATH' ) || exit;

/**
 * Placements are a single option, so they are read and written whole.
 *
 * There are never more than a handful, and a partial update of a set this small
 * buys nothing but a class of bug where two browser tabs disagree about what
 * the shop looks like.
 */
class PlacementsController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = Routes::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/admin/placements',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( Routes::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'replace' ),
					'permission_callback' => array( Routes::class, 'can_manage' ),
					'args'                => array(
						'placements' => array(
							'type'     => 'array',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Everything the placements screen needs, in one request.
	 *
	 * @return \WP_REST_Response
	 */
	public function index() {
		$surfaces = array();

		foreach ( SurfaceManager::all() as $id => $surface ) {
			$surfaces[] = array(
				'id'    => $id,
				'label' => $surface->get_label(),
			);
		}

		return rest_ensure_response(
			array(
				'placements'  => array_values( Placement::all() ),
				'surfaces'    => $surfaces,
				'hooks'       => Placement::hooks(),
				'scopes'      => Placement::scopes(),
				'collections' => \OCS\Model\Story::collections(),
			)
		);
	}

	/**
	 * Replace the whole set.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function replace( $request ) {
		$incoming = (array) $request['placements'];
		$clean    = array();

		foreach ( $incoming as $placement ) {
			if ( ! is_array( $placement ) ) {
				continue;
			}

			$sanitized = Placement::sanitize( $placement );

			if ( '' === $sanitized['id'] ) {
				$sanitized['id'] = Placement::new_id();
			}

			$clean[ $sanitized['id'] ] = $sanitized;
		}

		update_option( Placement::OPTION, array_values( $clean ), true );
		Placement::flush();

		// Rendered bars are cached against the placement they came from, so a
		// changed placement changes the key on its own. A deleted one would
		// otherwise sit in the cache until it expired.
		\OCS\Model\Story::bump_version();
		\OCS\Core\CacheFlush::pages();

		return rest_ensure_response( array( 'placements' => array_values( $clean ) ) );
	}
}
