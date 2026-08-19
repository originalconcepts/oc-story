<?php
/**
 * REST bootstrap.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the controllers that exist.
 *
 * Two namespaced groups with different rules: `/admin/*` needs a capability and
 * a nonce, and everything public must survive being called from a fully cached
 * page — which is why the events endpoint, when it lands, cannot take a nonce.
 */
class Routes {

	const NAMESPACE_V1 = 'oc-story/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register every available controller.
	 */
	public function register() {
		$controllers = array(
			\OCS\Rest\StoriesController::class,
			\OCS\Rest\ProductsController::class,
			\OCS\Rest\PlacementsController::class,
			\OCS\Rest\LookupController::class,
			\OCS\Rest\UploadController::class,
			\OCS\Rest\EventsController::class,
		);

		$controllers = apply_filters( 'ocs_rest_controllers', $controllers );

		foreach ( $controllers as $class ) {
			if ( class_exists( $class ) ) {
				$controller = new $class();
				$controller->register_routes();
			}
		}
	}

	/**
	 * Shared permission check for the admin routes.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}
}
