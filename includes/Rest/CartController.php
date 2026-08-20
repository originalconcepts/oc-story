<?php
/**
 * Buying without leaving the story.
 *
 * @package OC_Story
 */

namespace OCS\Rest;

use OCS\Model\Attribution;
use OCS\Model\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Two public routes: what a product's options are, and putting it in the cart.
 *
 * Public and nonce-free for the same reason the events beacon is — the player
 * runs on fully cached pages where a printed nonce is stale. The risk profile
 * matches WooCommerce's own `?add-to-cart=` links, which are nonce-free GET by
 * design: the worst a forged request achieves is a product in a cart, visible
 * and removable. Money still only moves at checkout, which has its own guards.
 */
class CartController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = Routes::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/product/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'options' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/cart',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A variable product's choices, compact enough for a bottom sheet.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function options( $request ) {
		$product = wc_get_product( (int) $request['id'] );

		if ( ! $product || ! $product->is_purchasable() ) {
			return new \WP_Error( 'ocs_no_product', __( 'That product is not available.', 'oc-story' ), array( 'status' => 404 ) );
		}

		$out = array(
			'id'         => $product->get_id(),
			'type'       => $product->get_type(),
			'in_stock'   => $product->is_in_stock(),
			'attributes' => array(),
			'variations' => array(),
		);

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_variation_attributes() as $attribute => $options ) {
				$row = array(
					'name'    => 'attribute_' . sanitize_title( $attribute ),
					'label'   => wc_attribute_label( $attribute, $product ),
					'options' => array(),
				);

				foreach ( (array) $options as $option ) {
					$term          = taxonomy_exists( $attribute ) ? get_term_by( 'slug', $option, $attribute ) : null;
					$row['options'][] = array(
						'slug'  => $option,
						'label' => $term instanceof \WP_Term ? $term->name : $option,
					);
				}

				$out['attributes'][] = $row;
			}

			foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
				$attrs = array();
				foreach ( $variation->get_variation_attributes( false ) as $name => $value ) {
					$attrs[ 'attribute_' . sanitize_title( $name ) ] = (string) $value;
				}

				$out['variations'][] = array(
					'id'       => $variation->get_id(),
					'attrs'    => $attrs,
					'price'    => html_entity_decode( wp_strip_all_tags( wc_price( $variation->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
					'in_stock' => $variation->is_in_stock(),
				);
			}
		}

		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'public, max-age=120' );

		return $response;
	}

	/**
	 * Put a product in the shopper's cart.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add( $request ) {
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'ocs_no_wc', __( 'The shop is not available.', 'oc-story' ), array( 'status' => 500 ) );
		}

		// Custom REST namespaces get no cart by default; WooCommerce ships the
		// loader precisely for this.
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( null === WC()->cart ) {
			return new \WP_Error( 'ocs_no_cart', __( 'The shop is not available.', 'oc-story' ), array( 'status' => 500 ) );
		}

		$product_id   = absint( $request['product'] );
		$variation_id = absint( $request['variation'] );
		$attributes   = array();

		foreach ( (array) $request['attributes'] as $key => $value ) {
			$key = sanitize_title( (string) $key );
			if ( 0 === strpos( $key, 'attribute_' ) ) {
				$attributes[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );

		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return new \WP_Error( 'ocs_no_product', __( 'That product is not available.', 'oc-story' ), array( 'status' => 404 ) );
		}

		// The story that sent the shopper here rides along, revalidated with
		// the same rules as the form path: right product, inside the window.
		$item_data = array();
		$claim     = Attribution::validate_claim(
			(string) $request['attr'],
			$product_id,
			$variation_id,
			time(),
			Attribution::window_seconds()
		);

		if ( null !== $claim ) {
			$item_data[ Attribution::CART_KEY ] = $claim;
		}

		$added = WC()->cart->add_to_cart( $product_id, 1, $variation_id, $attributes, $item_data );

		if ( ! $added ) {
			return new \WP_Error( 'ocs_not_added', __( 'That combination is not available.', 'oc-story' ), array( 'status' => 400 ) );
		}

		if ( null !== $claim ) {
			Stats::bump(
				array(
					array(
						'story_id' => $claim['story'],
						'slide_id' => $claim['slide'],
						'surface'  => '',
						'device'   => wp_is_mobile() ? 'm' : 'd',
						'counts'   => array( 'add_to_cart' => 1 ),
					),
				)
			);
		}

		return rest_ensure_response(
			array(
				'added' => true,
				'count' => (int) WC()->cart->get_cart_contents_count(),
			)
		);
	}
}
