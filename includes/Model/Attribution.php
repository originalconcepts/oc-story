<?php
/**
 * Revenue attribution.
 *
 * @package OC_Story
 */

namespace OCS\Model;

use OCS\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Connecting a sale back to the story that caused it.
 *
 * The chain: the player writes the tap to sessionStorage; on a product page a
 * tiny script copies it into the add-to-cart form; the cart carries it as item
 * data; checkout writes it to the order and counts the money. sessionStorage
 * rather than a cookie, deliberately — a cookie would vary every request on
 * the shop to record something only the checkout reads.
 *
 * Every hop revalidates. The client's claim is only ever a hint; the product
 * must match the item actually added, and the timestamp must sit inside the
 * attribution window at the add *and* at the checkout, because carts sleep for
 * weeks and a three-week-old tap did not cause today's order.
 */
class Attribution {

	const CART_KEY = 'ocs_attr';
	const META_KEY = '_ocs_attr';

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! Settings::is( 'attribution_enabled' ) || ! Settings::is( 'analytics_enabled' ) ) {
			return;
		}

		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'capture' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_item' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'count_order' ), 10, 3 );

		// The block checkout arrives through the Store API and never fires the
		// classic hook.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'count_order_object' ) );
	}

	/**
	 * Validate the claim posted with an add-to-cart and attach it to the item.
	 *
	 * @param array $cart_item_data Item data.
	 * @param int   $product_id     Product being added.
	 * @param int   $variation_id   Variation being added.
	 * @return array
	 */
	public function capture( $cart_item_data, $product_id, $variation_id = 0 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reached from cached pages; the payload is revalidated below and grants nothing.
		$raw = isset( $_POST[ self::CART_KEY ] ) ? wp_unslash( (string) $_POST[ self::CART_KEY ] ) : '';

		$claim = self::validate_claim( $raw, (int) $product_id, (int) $variation_id, time(), self::window_seconds() );

		if ( null === $claim ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::CART_KEY ] = $claim;

		Stats::bump(
			array(
				array(
					'story_id'  => $claim['story'],
					'slide_id'  => $claim['slide'],
					'surface'   => '',
					'placement' => isset( $claim['bar'] ) ? (string) $claim['bar'] : '',
					'device'    => wp_is_mobile() ? 'm' : 'd',
					'counts'    => array( 'add_to_cart' => 1 ),
				),
			)
		);

		return $cart_item_data;
	}

	/**
	 * Parse and check one claim. Pure, so the harness can pin it down.
	 *
	 * @param string $raw            The posted JSON.
	 * @param int    $product_id     Product actually added.
	 * @param int    $variation_id   Variation actually added.
	 * @param int    $now            Current unix time.
	 * @param int    $window_seconds Attribution window.
	 * @return array|null {story:int, slide:string, product:int, ts:int}
	 */
	public static function validate_claim( $raw, $product_id, $variation_id, $now, $window_seconds ) {
		if ( '' === $raw || strlen( $raw ) > 200 ) {
			return null;
		}

		$claim = json_decode( $raw, true );
		if ( ! is_array( $claim ) ) {
			return null;
		}

		$story   = isset( $claim['story'] ) ? (int) $claim['story'] : 0;
		$product = isset( $claim['product'] ) ? (int) $claim['product'] : 0;
		$slide   = isset( $claim['slide'] ) ? (string) $claim['slide'] : '';
		$bar     = isset( $claim['bar'] ) ? (string) $claim['bar'] : '';
		$ts      = isset( $claim['ts'] ) ? (int) ( $claim['ts'] / 1000 ) : 0;

		if ( $story < 1 || $product < 1 ) {
			return null;
		}

		if ( '' !== $slide && ! preg_match( '/^s_[a-f0-9]{8}$/', $slide ) ) {
			return null;
		}

		// Which gallery the shopper came through, so the sale can be credited
		// to it. Anything not shaped like a placement id is dropped rather
		// than carried: this arrives from a form field a shopper can edit.
		if ( '' !== $bar && ! preg_match( '/^[a-z0-9_\-]{1,32}$/', $bar ) ) {
			$bar = '';
		}

		// The tap must be for the product actually added — a claim for one
		// product must not earn credit for another landing in the same cart.
		if ( $product !== $product_id && $product !== $variation_id ) {
			return null;
		}

		if ( $ts <= 0 || $ts > $now || ( $now - $ts ) > $window_seconds ) {
			return null;
		}

		return array(
			'story'   => $story,
			'slide'   => $slide,
			'product' => $product,
			'bar'     => $bar,
			'ts'      => $ts,
		);
	}

	/**
	 * Carry the claim from the cart item onto the order line.
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array                  $values        Cart item values.
	 */
	public function stamp_item( $item, $cart_item_key, $values ) {
		if ( ! empty( $values[ self::CART_KEY ] ) && is_array( $values[ self::CART_KEY ] ) ) {
			$item->add_meta_data( self::META_KEY, $values[ self::CART_KEY ], true );
		}
	}

	/**
	 * Classic checkout.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $posted   Posted data.
	 * @param mixed $order    Order object.
	 */
	public function count_order( $order_id, $posted = array(), $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( $order ) {
			$this->count_order_object( $order );
		}
	}

	/**
	 * Credit each story that put money in this order, once.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function count_order_object( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Never count the same order twice — Store API and classic hooks can
		// both fire around one checkout.
		if ( $order->get_meta( self::META_KEY . '_counted' ) ) {
			return;
		}

		$window  = self::window_seconds();
		$now     = time();
		$per_key = array();
		$summary = array();

		foreach ( $order->get_items() as $item ) {
			$claim = $item->get_meta( self::META_KEY );

			if ( ! is_array( $claim ) || empty( $claim['story'] ) ) {
				continue;
			}

			// Carts sleep for weeks; the window applies at checkout too.
			if ( empty( $claim['ts'] ) || ( $now - (int) $claim['ts'] ) > $window ) {
				continue;
			}

			$story = (int) $claim['story'];
			$slide = isset( $claim['slide'] ) ? (string) $claim['slide'] : '';
			$bar   = isset( $claim['bar'] ) ? (string) $claim['bar'] : '';
			$key   = $story . '|' . $slide . '|' . $bar;

			if ( ! isset( $per_key[ $key ] ) ) {
				$per_key[ $key ] = array(
					'story_id'  => $story,
					'slide_id'  => $slide,
					'surface'   => '',
					'placement' => $bar,
					'device'    => '',
					'counts'    => array(
						'orders'  => 1,
						'revenue' => 0,
					),
				);
			}

			$per_key[ $key ]['counts']['revenue'] += (float) $item->get_total();

			$summary[] = array(
				'story'   => $story,
				'slide'   => $slide,
				'product' => (int) $item->get_product_id(),
				'total'   => (float) $item->get_total(),
			);
		}

		if ( ! $per_key ) {
			return;
		}

		Stats::bump( array_values( $per_key ) );

		$order->update_meta_data( self::META_KEY, $summary );
		$order->update_meta_data( self::META_KEY . '_counted', 1 );
		$order->save();

		do_action( 'ocs_order_attributed', $order->get_id(), $summary );
	}

	/**
	 * The attribution window in seconds.
	 *
	 * @return int
	 */
	public static function window_seconds() {
		$days = max( 1, min( 30, (int) Settings::get( 'attribution_days', 7 ) ) );

		return (int) apply_filters( 'ocs_attribution_window', $days * DAY_IN_SECONDS );
	}
}
