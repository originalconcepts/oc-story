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

		// Everything the wide panel shows. The narrow one ignores most of it,
		// and the page payload carries none of it — this is fetched on a tap,
		// by somebody who has already decided to look.
		$gallery = array();

		foreach ( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) as $image_id ) {
			$url = $image_id ? (string) wp_get_attachment_image_url( (int) $image_id, 'woocommerce_single' ) : '';

			if ( '' !== $url ) {
				$gallery[] = $url;
			}
		}

		$out = array(
			'id'         => $product->get_id(),
			'type'       => $product->get_type(),
			'in_stock'   => $product->is_in_stock(),
			'name'       => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
			'url'        => $product->get_permalink(),
			'images'     => $gallery,
			'excerpt'    => html_entity_decode( wp_strip_all_tags( $product->get_short_description() ), ENT_QUOTES, 'UTF-8' ),
			'price'      => html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
			'was'        => $product->is_on_sale()
				? html_entity_decode( wp_strip_all_tags( wc_price( $product->get_regular_price() ) ), ENT_QUOTES, 'UTF-8' )
				: '',
			'rating'     => round( (float) $product->get_average_rating(), 1 ),
			'reviews'    => (int) $product->get_review_count(),
			'max'        => $product->get_max_purchase_quantity() > 0 ? (int) $product->get_max_purchase_quantity() : 0,
			'attributes' => array(),
			'variations' => array(),
		);

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_variation_attributes() as $attribute => $options ) {
				$row = array(
					'name'    => 'attribute_' . sanitize_title( $attribute ),
					'label'   => wc_attribute_label( $attribute, $product ),
					'style'   => $this->attribute_style( $attribute ),
					'options' => array(),
				);

				foreach ( (array) $options as $option ) {
					$term  = taxonomy_exists( $attribute ) ? get_term_by( 'slug', $option, $attribute ) : null;
					$entry = array(
						'slug'  => $option,
						'label' => $term instanceof \WP_Term ? $term->name : $option,
					);

					// The OC Theme's swatch scheme: colour and image live on the
					// term, the attribute type decides which leads. Read behind
					// a filter so another theme's scheme can plug its own in.
					if ( $term instanceof \WP_Term ) {
						$entry['color'] = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );
						$entry['image'] = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );
					}

					$row['options'][] = apply_filters( 'ocs_sheet_option', $entry, $attribute, $product );
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
	 * How the sheet renders one attribute, following the theme's own choice.
	 *
	 * WooCommerce stores a type on every global attribute; the OC Theme adds
	 * 'button', 'swatch' and 'swatch_image' to the stock 'select'. The sheet
	 * mirrors whatever the product page shows, so the story never feels like a
	 * different shop.
	 *
	 * @param string $attribute Attribute taxonomy name.
	 * @return string 'swatch' | 'button' | 'select'.
	 */
	protected function attribute_style( $attribute ) {
		$style = 'select';

		if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) && function_exists( 'wc_get_attribute' ) ) {
			$id = wc_attribute_taxonomy_id_by_name( $attribute );

			if ( $id ) {
				$meta = wc_get_attribute( $id );
				$type = $meta ? (string) $meta->type : 'select';

				if ( in_array( $type, array( 'swatch', 'swatch_image' ), true ) ) {
					$style = 'swatch';
				} elseif ( 'button' === $type ) {
					$style = 'button';
				}
			}
		} else {
			$style = 'button';
		}

		return (string) apply_filters( 'ocs_sheet_attribute_style', $style, $attribute );
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

		// A guest's cart lives in a session the browser has to be told about.
		// Without this cookie the item is saved to a session no future request
		// carries, and the cart looks empty the moment they leave the story.
		if ( ! is_user_logged_in() && WC()->session && is_callable( array( WC()->session, 'set_customer_session_cookie' ) ) ) {
			WC()->session->set_customer_session_cookie( true );
		}

		$product_id   = absint( $request['product'] );
		$variation_id = absint( $request['variation'] );
		// The wide panel has a stepper. Clamped rather than trusted, and
		// WooCommerce still gets the last word on stock.
		$quantity     = max( 1, min( 999, absint( $request['quantity'] ) ) );
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

		$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes, $item_data );

		if ( ! $added ) {
			// WooCommerce queued the real reason as a notice — "sold
			// individually", "not enough stock" — and the shopper deserves it
			// verbatim rather than a shrug.
			$message = __( 'That combination is not available.', 'oc-story' );

			if ( function_exists( 'wc_get_notices' ) ) {
				foreach ( wc_get_notices( 'error' ) as $notice ) {
					$text = is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice;
					$text = trim( wp_strip_all_tags( $text ) );
					if ( '' !== $text ) {
						$message = $text;
						break;
					}
				}
				wc_clear_notices();
			}

			return new \WP_Error( 'ocs_not_added', $message, array( 'status' => 400 ) );
		}

		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
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

		// The same fragment payload WooCommerce's own AJAX add returns — the
		// theme's header count and cart drawer are wired to exactly this
		// channel, so applying it client-side updates them on the spot.
		$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', array() );

		return rest_ensure_response(
			array(
				'added'     => true,
				'count'     => (int) WC()->cart->get_cart_contents_count(),
				'hash'      => function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_hash() : '',
				'fragments' => is_array( $fragments ) && $fragments ? $fragments : null,
			)
		);
	}
}
