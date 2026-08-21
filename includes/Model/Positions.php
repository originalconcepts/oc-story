<?php
/**
 * The catalogue of places a gallery can appear.
 *
 * @package OC_Story
 */

namespace OCS\Model;

defined( 'ABSPATH' ) || exit;

/**
 * One table that the whole product agrees on.
 *
 * The wizard draws its choices from here, the injector resolves a stored
 * choice back to a hook from here, and the position check knows from here
 * whether a spot is one a theme can quietly not have. Three features reading
 * one table is the only way they stay in step: a position that exists in the
 * picker and nowhere else is a promise the shop never keeps.
 *
 * A position is named for what the shopper sees ("below the add-to-cart
 * button"), never for the hook underneath. Hook names are an implementation
 * detail that leaked into the old settings screen and made it unreadable.
 */
class Positions {

	/**
	 * The three kinds of gallery.
	 *
	 * `cards` covers the slider and the wall — they are the same gallery seen
	 * two ways, and the choice between them is a display setting rather than a
	 * different thing to build.
	 *
	 * @return array<string,array>
	 */
	public static function types() {
		return array(
			'story'    => array(
				'label'    => __( 'Story', 'oc-story' ),
				'note'     => __( 'Round covers in a row. Tapping one opens it full-screen.', 'oc-story' ),
				'surfaces' => array( 'circles' ),
			),
			'cards'    => array(
				'label'    => __( 'Slider / wall', 'oc-story' ),
				'note'     => __( 'Video cards that play themselves, in a row or a grid.', 'oc-story' ),
				'surfaces' => array( 'slider', 'grid' ),
			),
			'floating' => array(
				'label'    => __( 'Floating video', 'oc-story' ),
				'note'     => __( 'A small video in the corner of the page, opened on tap.', 'oc-story' ),
				'surfaces' => array( 'floating' ),
			),
		);
	}

	/**
	 * Which pages a gallery can be sent to.
	 *
	 * These map onto the placement scopes that already route every request —
	 * the wizard renames them for a shop owner and adds nothing new to the
	 * rules engine.
	 *
	 * @return array<string,array>
	 */
	public static function targets() {
		return array(
			'home'     => array(
				'label' => __( 'The home page', 'oc-story' ),
				'scope' => 'home',
			),
			'product'  => array(
				'label' => __( 'Product pages', 'oc-story' ),
				'scope' => 'products', // Or 'tagged' when the gallery picks its own products.
			),
			'category' => array(
				'label' => __( 'Category pages', 'oc-story' ),
				'scope' => 'terms',
			),
			'page'     => array(
				'label' => __( 'A specific page', 'oc-story' ),
				'scope' => 'pages',
			),
			'site'     => array(
				'label' => __( 'Every page of the shop', 'oc-story' ),
				'scope' => 'site',
			),
			'custom'   => array(
				'label' => __( 'Wherever I place it myself', 'oc-story' ),
				'scope' => 'site',
			),
		);
	}

	/**
	 * Every position, by the hook it really is.
	 *
	 * `theme` marks the ones that only exist while the theme still renders
	 * WooCommerce's own templates. A product page built in a page builder
	 * replaces the summary wholesale and none of those hooks fire — which is
	 * why the picker warns and the check verifies rather than assuming.
	 *
	 * @return array<string,array>
	 */
	protected static function catalogue() {
		return array(
			'above_content'  => array(
				'label'    => __( 'Above the page content', 'oc-story' ),
				'hook'     => 'auto',
				'priority' => 15,
				'theme'    => false,
			),
			'end_of_content' => array(
				'label'    => __( 'At the bottom of the page content', 'oc-story' ),
				'hook'     => 'content_end',
				'priority' => 15,
				'theme'    => false,
			),
			'before_cart'    => array(
				'label'    => __( 'Above the add-to-cart button', 'oc-story' ),
				'hook'     => 'woocommerce_single_product_summary',
				'priority' => 25,
				'theme'    => true,
			),
			'after_cart'     => array(
				'label'    => __( 'Below the add-to-cart button', 'oc-story' ),
				'hook'     => 'woocommerce_single_product_summary',
				'priority' => 35,
				'theme'    => true,
			),
			'after_summary'  => array(
				'label'    => __( 'Below the gallery and the description', 'oc-story' ),
				'hook'     => 'woocommerce_after_single_product_summary',
				'priority' => 10,
				'theme'    => true,
			),
			'above_products' => array(
				'label'    => __( 'Above the product list', 'oc-story' ),
				'hook'     => 'woocommerce_before_shop_loop',
				'priority' => 15,
				'theme'    => true,
			),
			'below_products' => array(
				'label'    => __( 'Below the product list', 'oc-story' ),
				'hook'     => 'woocommerce_after_shop_loop',
				'priority' => 15,
				'theme'    => true,
			),
			'side_start'     => array(
				'label'    => __( 'Corner, at the start of the line', 'oc-story' ),
				'hook'     => 'ocs_floating',
				'priority' => 15,
				'theme'    => false,
			),
			'side_end'       => array(
				'label'    => __( 'Corner, at the end of the line', 'oc-story' ),
				'hook'     => 'ocs_floating',
				'priority' => 15,
				'theme'    => false,
			),
			'custom'         => array(
				'label'    => __( 'Somewhere I choose myself', 'oc-story' ),
				'hook'     => 'manual',
				'priority' => 15,
				'theme'    => false,
			),
		);
	}

	/**
	 * The positions offered for one kind of gallery on one kind of page.
	 *
	 * Every branch ends in `custom`, on purpose: the shortcode is the escape
	 * hatch that keeps a picker of pictures from becoming a cage.
	 *
	 * @param string $type   A key from types().
	 * @param string $target A key from targets().
	 * @return array<string,array> Position key => catalogue entry.
	 */
	public static function offered( $type, $target ) {
		$matrix = array(
			'story'    => array(
				'home'     => array( 'above_content', 'custom' ),
				'product'  => array( 'before_cart', 'after_cart', 'after_summary', 'custom' ),
				'category' => array( 'above_products', 'below_products', 'custom' ),
				'page'     => array( 'above_content', 'end_of_content', 'custom' ),
				'site'     => array( 'above_content', 'custom' ),
				'custom'   => array( 'custom' ),
			),
			'cards'    => array(
				'home'     => array( 'end_of_content', 'custom' ),
				'product'  => array( 'before_cart', 'after_cart', 'after_summary', 'end_of_content', 'custom' ),
				'category' => array( 'above_products', 'below_products', 'custom' ),
				'page'     => array( 'above_content', 'end_of_content', 'custom' ),
				'site'     => array( 'end_of_content', 'custom' ),
				'custom'   => array( 'custom' ),
			),
			// A floating video is a corner, and a corner is the same corner on
			// every kind of page. There is nothing to choose but which side.
			'floating' => array(
				'home'     => array( 'side_start', 'side_end' ),
				'product'  => array( 'side_start', 'side_end' ),
				'category' => array( 'side_start', 'side_end' ),
				'page'     => array( 'side_start', 'side_end' ),
				'site'     => array( 'side_start', 'side_end' ),
				'custom'   => array( 'side_start', 'side_end' ),
			),
		);

		$keys = isset( $matrix[ $type ][ $target ] ) ? $matrix[ $type ][ $target ] : array( 'custom' );

		$catalogue = self::catalogue();
		$out       = array();

		foreach ( $keys as $key ) {
			if ( isset( $catalogue[ $key ] ) ) {
				$out[ $key ] = $catalogue[ $key ];
			}
		}

		/**
		 * Filter the positions offered for one branch of the wizard.
		 *
		 * A theme that renders its own product page can add the spot it does
		 * have, and remove the three it does not.
		 *
		 * @param array  $out    Position key => entry.
		 * @param string $type   Gallery type.
		 * @param string $target Page target.
		 */
		return (array) apply_filters( 'ocs_positions', $out, $type, $target );
	}

	/**
	 * One position, resolved to the hook that renders it.
	 *
	 * Unknown keys fall back to the recommended spot rather than to nothing:
	 * a gallery that someone deliberately created should appear somewhere,
	 * even after a theme filter has taken its position away.
	 *
	 * @param string $key Position key.
	 * @return array
	 */
	public static function get( $key ) {
		$catalogue = self::catalogue();

		if ( isset( $catalogue[ $key ] ) ) {
			return array_merge( array( 'key' => $key ), $catalogue[ $key ] );
		}

		return array_merge( array( 'key' => 'above_content' ), $catalogue['above_content'] );
	}

	/**
	 * Read a position back out of the hook a placement already routes through.
	 *
	 * Every widget made before the wizard existed has a hook and no position,
	 * and the wizard treats a missing position as an unanswered question — so
	 * those galleries opened with step two blank and step three locked, and
	 * the only way forward was to choose again something they had already
	 * chosen.
	 *
	 * Only an exact match counts. A hook that no position maps to keeps its
	 * empty position and its raw hook, because guessing would move somebody's
	 * gallery to a spot they never picked.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $priority Hook priority.
	 * @return string Position key, or '' when nothing matches exactly.
	 */
	public static function for_hook( $hook, $priority ) {
		foreach ( self::catalogue() as $key => $spot ) {
			if ( $spot['hook'] === $hook && (int) $spot['priority'] === (int) $priority ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Whether this position depends on the theme using WooCommerce templates.
	 *
	 * @param string $key Position key.
	 * @return bool
	 */
	public static function needs_theme_support( $key ) {
		$position = self::get( $key );

		return ! empty( $position['theme'] );
	}

	/**
	 * The type a surface belongs to.
	 *
	 * Derived rather than stored. Two fields that must agree are two fields
	 * that will one day disagree.
	 *
	 * @param string $surface Surface id.
	 * @return string
	 */
	public static function type_of( $surface ) {
		foreach ( self::types() as $type => $spec ) {
			if ( in_array( $surface, $spec['surfaces'], true ) ) {
				return $type;
			}
		}

		return 'cards';
	}
}
