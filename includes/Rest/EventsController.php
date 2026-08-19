<?php
/**
 * The events beacon.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

use OCS\Core\Settings;
use OCS\Model\Stats;
use OCS\Surfaces\SurfaceManager;

defined( 'ABSPATH' ) || exit;

/**
 * Public and nonce-free, because it has to be.
 *
 * The beacon fires from fully cached pages, where a printed nonce would be
 * hours stale and rejected. The defence is what the endpoint is allowed to do
 * instead of who may call it: it increments bounded counters against a strict
 * schema (Stats::normalize_batch, covered by the harness), stores nothing a
 * request supplies beyond validated enums, and is rate-limited per address.
 */
class EventsController {

	const LIMIT_PER_HOUR = 120;

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			Routes::NAMESPACE_V1,
			'/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Take one beacon.
	 *
	 * Always answers 204, even to garbage. An error body invites retries from
	 * the sender and tells a prober what the filter rejected; silence does
	 * neither, and the shopper this fires for has already left the page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function receive( $request ) {
		$done = new \WP_REST_Response( null, 204 );

		if ( ! Settings::is( 'analytics_enabled' ) ) {
			return $done;
		}

		if ( ! $this->within_rate_limit() ) {
			return $done;
		}

		$batch = json_decode( $request->get_body(), true );
		$rows  = Stats::normalize_batch( $batch, SurfaceManager::ids() );

		if ( $rows ) {
			Stats::bump( $rows );
		}

		return $done;
	}

	/**
	 * A coarse per-address budget.
	 *
	 * A real session sends a handful of beacons; three digits an hour is
	 * nobody's shopping trip. Sitting on the object cache when one exists, it
	 * costs a get and an occasional set.
	 *
	 * @return bool
	 */
	protected function within_rate_limit() {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $address ) {
			return false;
		}

		$key   = 'ocs_ev_' . md5( $address );
		$count = (int) get_transient( $key );

		if ( $count >= self::LIMIT_PER_HOUR ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}
}
