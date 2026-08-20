<?php
/**
 * Product lookups for the studio and the player.
 *
 * @package OC_Story
 */

namespace OCS\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Turns product IDs into the handful of fields a story card needs.
 *
 * Names, prices and thumbnails are resolved here every time and never stored on
 * a slide. A price that was right when the video was uploaded is not right six
 * months later, and a cached story showing a stale price is a consumer-law
 * problem before it is a bug.
 */
class Products {

	/**
	 * Summarise a set of products in one query.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int,array> Keyed by product ID, in the order given.
	 */
	public static function summaries( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! $ids || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'include' => $ids,
				'limit'   => count( $ids ),
				'status'  => array( 'publish', 'private' ),
			)
		);

		$found = array();
		foreach ( $products as $product ) {
			$found[ $product->get_id() ] = self::summarise( $product );
		}

		// Preserve the order the slide asked for, and drop anything that has
		// since been deleted rather than rendering a gap.
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $found[ $id ] ) ) {
				$out[ $id ] = $found[ $id ];
			}
		}

		return $out;
	}

	/**
	 * Search products for the studio's autocomplete.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Maximum results.
	 * @return array<int,array>
	 */
	public static function search( $term, $limit = 20 ) {
		$term  = trim( (string) $term );
		$limit = max( 1, min( 50, (int) $limit ) );

		if ( '' === $term || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$args = array(
			'limit'   => $limit,
			'status'  => array( 'publish', 'private' ),
			'orderby' => 'relevance',
			'return'  => 'objects',
		);

		// A bare number is far more likely to be an ID or a SKU than a name, and
		// a shop owner reaching for one has the packing slip in front of them.
		if ( ctype_digit( $term ) ) {
			$by_id = wc_get_product( (int) $term );
			if ( $by_id ) {
				return array( self::summarise( $by_id ) );
			}
		}

		$args['s'] = $term;

		$products = wc_get_products( $args );

		$out = array();
		foreach ( $products as $product ) {
			$out[] = self::summarise( $product );
		}

		return $out;
	}

	/**
	 * One product, reduced to what a card shows.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	protected static function summarise( $product ) {
		$image_id = $product->get_image_id();

		return array(
			'id'       => $product->get_id(),
			'name'     => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
			'sku'      => $product->get_sku(),
			'price'    => self::price_text( $product ),
			'url'      => $product->get_permalink(),
			'thumb'    => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '',
			// Through WooCommerce's own aggregates, which OC Reviews keeps
			// fresh — never through comment meta directly.
			'rating'   => round( (float) $product->get_average_rating(), 1 ),
			'reviews'  => (int) $product->get_review_count(),
			'variable' => $product->is_type( 'variable' ),
		);
	}

	/**
	 * The price as plain text a card can print.
	 *
	 * Not get_price_html(): on a sale product that carries the crossed-out old
	 * price, the new one, and two screen-reader sentences — and stripping its
	 * tags leaves the whole speech plus raw entities on the card. A card wants
	 * the number the shopper pays, so it is built from the actual price and
	 * decoded down to text (the currency sign arrives as an entity).
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	protected static function price_text( $product ) {
		$amount = $product->get_price();

		if ( '' === $amount || null === $amount || ! function_exists( 'wc_price' ) ) {
			return '';
		}

		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
