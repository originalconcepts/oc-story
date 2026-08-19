<?php
/**
 * Placements: which surface renders where, and how it looks per device.
 *
 * @package OC_Story
 */

namespace OCS\Model;

use OCS\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Placements are not a table.
 *
 * There are rarely more than a dozen and they are needed on every page load, so
 * they live in one non-autoloaded option read once per request. A table would
 * cost a query on every page to save writes that happen a few times a year.
 *
 * `matches()` is the whole rules engine and is deliberately free of WordPress:
 * `Display\Injector` builds a plain context array from the current query and
 * passes it in, which makes the routing testable without a WordPress install.
 */
class Placement {

	const OPTION = 'ocs_placements';

	/**
	 * Surfaces that ship in 0.1.
	 *
	 * @var string[]
	 */
	const SURFACES = array( 'circles', 'slider', 'product' );

	/**
	 * Where a placement can apply.
	 *
	 * @var string[]
	 */
	const SCOPES = array( 'site', 'home', 'pages', 'products', 'terms', 'tagged' );

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * A placement with nothing filled in.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'       => '',
			'label'    => '',
			'enabled'  => true,
			'surface'  => 'circles',
			'where'    => array(
				'scope'   => 'home',
				'ids'     => array(),
				'exclude' => array(),
			),
			'hook'     => 'woocommerce_before_main_content',
			'priority' => 15,
			'stories'  => array(
				'mode' => 'all',   // 'all' | 'selected' | 'tagged'.
				'ids'  => array(),
			),
			'desktop'  => array(
				'show'   => true,
				'size'   => (int) Settings::get( 'desktop_size', 84 ),
				'labels' => Settings::is( 'desktop_labels' ),
				'align'  => 'start',
				'max'    => (int) Settings::get( 'desktop_max', 12 ),
			),
			'mobile'   => array(
				'show'   => true,
				'size'   => (int) Settings::get( 'mobile_size', 64 ),
				'labels' => Settings::is( 'mobile_labels' ),
				'align'  => 'start',
				'max'    => (int) Settings::get( 'mobile_max', 20 ),
			),
		);
	}

	/**
	 * Every placement, sanitised, keyed by id.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$out    = array();

			foreach ( (array) $stored as $placement ) {
				if ( ! is_array( $placement ) ) {
					continue;
				}
				$clean = self::sanitize( $placement );
				if ( '' !== $clean['id'] ) {
					$out[ $clean['id'] ] = $clean;
				}
			}

			self::$cache = $out;
		}

		return self::$cache;
	}

	/**
	 * One placement.
	 *
	 * @param string $id Placement id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Create or replace a placement.
	 *
	 * @param array $placement Raw placement.
	 * @return array The stored, sanitised placement.
	 */
	public static function save( array $placement ) {
		$clean = self::sanitize( $placement );
		if ( '' === $clean['id'] ) {
			$clean['id'] = self::new_id();
		}

		$all               = self::all();
		$all[ $clean['id'] ] = $clean;

		self::persist( $all );

		return $clean;
	}

	/**
	 * Remove a placement.
	 *
	 * @param string $id Placement id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		self::persist( $all );
		return true;
	}

	/**
	 * Write the whole set back.
	 *
	 * @param array $all Placements keyed by id.
	 */
	protected static function persist( array $all ) {
		self::$cache = $all;
		update_option( self::OPTION, array_values( $all ), false );
	}

	/**
	 * Drop the runtime cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * A new placement id.
	 *
	 * @return string
	 */
	public static function new_id() {
		return 'pl_' . substr( md5( uniqid( 'ocs', true ) ), 0, 8 );
	}

	/**
	 * Coerce raw input into a valid placement.
	 *
	 * @param array $raw Raw placement.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$defaults = self::defaults();

		$id = isset( $raw['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $raw['id'] ) ) : '';

		$surface = isset( $raw['surface'] ) ? strtolower( (string) $raw['surface'] ) : $defaults['surface'];
		if ( ! in_array( $surface, self::surfaces(), true ) ) {
			$surface = $defaults['surface'];
		}

		$scope = isset( $raw['where']['scope'] ) ? strtolower( (string) $raw['where']['scope'] ) : $defaults['where']['scope'];
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			$scope = $defaults['where']['scope'];
		}

		$mode = isset( $raw['stories']['mode'] ) ? strtolower( (string) $raw['stories']['mode'] ) : 'all';
		if ( ! in_array( $mode, array( 'all', 'selected', 'tagged' ), true ) ) {
			$mode = 'all';
		}

		$label = isset( $raw['label'] ) ? trim( wp_strip_all_tags( (string) $raw['label'] ) ) : '';

		return array(
			'id'       => $id,
			'label'    => mb_substr( $label, 0, 60 ),
			'enabled'  => self::flag( isset( $raw['enabled'] ) ? $raw['enabled'] : true ),
			'surface'  => $surface,
			'where'    => array(
				'scope'   => $scope,
				'ids'     => self::ids( isset( $raw['where']['ids'] ) ? $raw['where']['ids'] : array() ),
				'exclude' => self::ids( isset( $raw['where']['exclude'] ) ? $raw['where']['exclude'] : array() ),
			),
			'hook'     => isset( $raw['hook'] ) ? preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $raw['hook'] ) : $defaults['hook'],
			'priority' => isset( $raw['priority'] ) ? max( 1, min( 999, (int) $raw['priority'] ) ) : $defaults['priority'],
			'stories'  => array(
				'mode' => $mode,
				'ids'  => self::ids( isset( $raw['stories']['ids'] ) ? $raw['stories']['ids'] : array() ),
			),
			'desktop'  => self::device( isset( $raw['desktop'] ) ? $raw['desktop'] : array(), $defaults['desktop'] ),
			'mobile'   => self::device( isset( $raw['mobile'] ) ? $raw['mobile'] : array(), $defaults['mobile'] ),
		);
	}

	/**
	 * Per-device layout.
	 *
	 * Desktop and mobile are separate objects rather than one config with
	 * breakpoint overrides, because that is how the setting is actually thought
	 * about — "smaller circles on the phone, no captions" — and because both
	 * ship in the same markup so CSS can pick without splitting the cache.
	 *
	 * @param mixed $raw      Raw device config.
	 * @param array $defaults Device defaults.
	 * @return array
	 */
	protected static function device( $raw, array $defaults ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$align = isset( $raw['align'] ) ? strtolower( (string) $raw['align'] ) : $defaults['align'];
		if ( ! in_array( $align, array( 'start', 'center', 'end' ), true ) ) {
			$align = $defaults['align'];
		}

		return array(
			'show'   => self::flag( isset( $raw['show'] ) ? $raw['show'] : $defaults['show'] ),
			'size'   => isset( $raw['size'] ) ? max( 40, min( 160, (int) $raw['size'] ) ) : $defaults['size'],
			'labels' => self::flag( isset( $raw['labels'] ) ? $raw['labels'] : $defaults['labels'] ),
			'align'  => $align,
			'max'    => isset( $raw['max'] ) ? max( 1, min( 50, (int) $raw['max'] ) ) : $defaults['max'],
		);
	}

	/**
	 * A list of positive integer ids.
	 *
	 * @param mixed $raw Raw list.
	 * @return int[]
	 */
	protected static function ids( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = ( '' === $raw || null === $raw ) ? array() : explode( ',', (string) $raw );
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Booleanise the several shapes a checkbox arrives in.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	protected static function flag( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( 'yes', 'true', '1', 'on' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Registered surface ids.
	 *
	 * @return string[]
	 */
	public static function surfaces() {
		$ids = apply_filters( 'ocs_surface_ids', self::SURFACES );
		return array_values( array_filter( array_map( 'strval', (array) $ids ) ) );
	}

	/**
	 * Where a bar can be hooked, in words a shop owner can choose between.
	 *
	 * A raw hook name is meaningless to the person deciding where their videos
	 * go, and the list of hooks that actually exist is long and mostly wrong.
	 * These are the positions worth offering; a developer who wants another one
	 * adds it through `ocs_placement_hooks`.
	 *
	 * @return array<string,string> Hook => label.
	 */
	public static function hooks() {
		return (array) apply_filters(
			'ocs_placement_hooks',
			array(
				'manual'                                => __( 'Nowhere automatic — I will place it myself', 'oc-story' ),
				'wp_body_open'                          => __( 'Very top of the page, above everything', 'oc-story' ),
				'woocommerce_before_main_content'       => __( 'Above the page content', 'oc-story' ),
				'woocommerce_after_main_content'        => __( 'Below the page content', 'oc-story' ),
				'woocommerce_before_shop_loop'          => __( 'Above the product grid', 'oc-story' ),
				'woocommerce_after_shop_loop'           => __( 'Below the product grid', 'oc-story' ),
				'woocommerce_before_single_product'     => __( 'Product page — above everything', 'oc-story' ),
				'woocommerce_single_product_summary'    => __( 'Product page — beside the price and button', 'oc-story' ),
				'woocommerce_after_single_product_summary' => __( 'Product page — below the description', 'oc-story' ),
				'get_footer'                            => __( 'Just before the footer', 'oc-story' ),
			)
		);
	}

	/**
	 * The scopes, labelled.
	 *
	 * @return array<string,string>
	 */
	public static function scopes() {
		return array(
			'site'     => __( 'Every page of the site', 'oc-story' ),
			'home'     => __( 'The home page only', 'oc-story' ),
			'pages'    => __( 'Only the pages I choose', 'oc-story' ),
			'products' => __( 'Product pages', 'oc-story' ),
			'terms'    => __( 'Products in certain categories', 'oc-story' ),
			'tagged'   => __( 'Product pages — showing the videos that tag that product', 'oc-story' ),
		);
	}

	/**
	 * Does this placement apply to the current request?
	 *
	 * Pure logic. The context is a plain array so that routing can be tested
	 * without WordPress:
	 *
	 *     'is_front'   bool   the site's front page
	 *     'is_shop'    bool   the WooCommerce shop archive
	 *     'is_product' bool   a single product
	 *     'post_id'    int    the queried post, 0 on an archive
	 *     'product_id' int    the queried product, 0 elsewhere
	 *     'term_ids'   int[]  product category / tag ids in play
	 *
	 * @param array $where   The placement's `where` block.
	 * @param array $context Request context.
	 * @return bool
	 */
	public static function matches( array $where, array $context ) {
		$context = wp_parse_args(
			$context,
			array(
				'is_front'   => false,
				'is_shop'    => false,
				'is_product' => false,
				'post_id'    => 0,
				'product_id' => 0,
				'term_ids'   => array(),
			)
		);

		$scope   = isset( $where['scope'] ) ? $where['scope'] : 'site';
		$ids     = isset( $where['ids'] ) ? (array) $where['ids'] : array();
		$exclude = isset( $where['exclude'] ) ? (array) $where['exclude'] : array();

		// Exclusions win over everything, and are checked against whichever id
		// identifies this request.
		$current = (int) $context['post_id'] ? (int) $context['post_id'] : (int) $context['product_id'];
		if ( $current && in_array( $current, array_map( 'intval', $exclude ), true ) ) {
			return false;
		}

		switch ( $scope ) {
			case 'site':
				$match = true;
				break;

			case 'home':
				$match = (bool) $context['is_front'];
				break;

			case 'pages':
				$match = $current > 0 && in_array( $current, array_map( 'intval', $ids ), true );
				break;

			case 'products':
				// With no ids, every product page. With ids, only those products.
				$match = (bool) $context['is_product']
					&& ( ! $ids || in_array( (int) $context['product_id'], array_map( 'intval', $ids ), true ) );
				break;

			case 'terms':
				$match = (bool) array_intersect(
					array_map( 'intval', (array) $context['term_ids'] ),
					array_map( 'intval', $ids )
				);
				break;

			case 'tagged':
				// Product pages, showing whichever stories tag this product. The
				// story selection does the real filtering; the placement just has
				// to be on a product page for it to mean anything.
				$match = (bool) $context['is_product'];
				break;

			default:
				$match = false;
		}

		return (bool) apply_filters( 'ocs_placement_matches', $match, $where, $context );
	}
}
