<?php
/**
 * Placing surfaces on the page.
 *
 * @package OC_Story
 */

namespace OCS\Display;

use OCS\Model\Placement;
use OCS\Model\Story;
use OCS\Surfaces\SurfaceManager;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which placements apply to this request, and hooks only those.
 *
 * Evaluation happens once on `wp`, before anything renders, so a page with no
 * placement on it registers nothing at all: no hooks, no query, no assets. That
 * is what lets the plugin be installed on a shop and be genuinely absent from
 * the pages it is not wanted on.
 */
class Injector {

	/**
	 * Placements that apply to this request.
	 *
	 * @var array<int,array>
	 */
	protected $active = array();

	/**
	 * Whether anything at all will render.
	 *
	 * @var bool
	 */
	protected static $anything = false;

	/**
	 * Surface ids that will render on this request.
	 *
	 * @var array<string,bool>
	 */
	protected static $surfaces = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp', array( $this, 'route' ), 20 );
	}

	/**
	 * Decide what renders where.
	 */
	public function route() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}

		$context = self::context();

		foreach ( Placement::all() as $placement ) {
			if ( ! $placement['enabled'] || 'manual' === $placement['hook'] ) {
				continue;
			}

			$surface = SurfaceManager::get( $placement['surface'] );
			if ( ! $surface || ! $surface->supports( $context ) ) {
				continue;
			}

			if ( ! Placement::matches( $placement['where'], $context ) ) {
				continue;
			}

			$this->active[] = $placement;
			self::$anything = true;

			// Only the stylesheet for a surface that is actually on the page
			// gets inlined. A shop running circles alone should never carry the
			// slider's CSS in the HTML of every page.
			self::$surfaces[ $placement['surface'] ] = true;

			if ( 'auto' === $placement['hook'] ) {
				$this->attach_auto( $placement, $context );
				continue;
			}

			add_action(
				$placement['hook'],
				function () use ( $placement, $context ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::render( $placement, $context );
				},
				(int) $placement['priority']
			);
		}
	}

	/**
	 * "Where the page content starts", on whatever kind of page this is.
	 *
	 * The default position used to be a WooCommerce hook, and the first real
	 * install showed why that cannot be the default: it simply does not exist
	 * on a home page, and the person who chose "every page of the site" watched
	 * an empty home page wondering where their story went. A shop owner should
	 * not need to know what a hook is, so 'auto' picks the anchor that this
	 * request actually has:
	 *
	 *   WooCommerce pages   woocommerce_before_main_content — the classic spot
	 *   any singular page   prepended to the content (Elementor runs this too)
	 *   everything else     the top of the main loop (a blog home, an archive)
	 *
	 * @param array $placement Placement.
	 * @param array $context   Request context.
	 */
	protected function attach_auto( array $placement, array $context ) {
		$done = false;

		$emit = function () use ( &$done, $placement, $context ) {
			if ( $done ) {
				return '';
			}
			$done = true;

			return self::render( $placement, $context );
		};

		$print = function () use ( $emit ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $emit();
		};

		$is_woo = $context['is_product']
			|| $context['is_shop']
			|| ( function_exists( 'is_product_category' ) && ( is_product_category() || is_product_tag() ) );

		if ( $is_woo ) {
			add_action( 'woocommerce_before_main_content', $print, (int) $placement['priority'] );
		} elseif ( is_singular() ) {
			add_filter(
				'the_content',
				function ( $content ) use ( $emit ) {
					// Widgets and builders run this filter on fragments too;
					// only the main content of the main query gets the bar.
					if ( ! is_main_query() || ! in_the_loop() ) {
						return $content;
					}

					return $emit() . $content;
				},
				// After wpautop, which sits at 10. Prepending earlier hands our
				// markup to it, and it turns the newlines between our own tags
				// into <br> — which is what turned the circles into ellipses:
				// two stray line boxes inside a ring whose height is supposed
				// to come from its aspect ratio.
				20
			);
		} else {
			// An archive or a blog home. loop_start is the right spot — but a
			// loop with nothing in it never starts, and a shop's "blog" home
			// with zero posts is exactly the page this was first watched fail
			// on. The main query has already run by `wp`, so ask it.
			$has_loop = isset( $GLOBALS['wp_query'] ) && (int) $GLOBALS['wp_query']->post_count > 0;

			if ( $has_loop ) {
				add_action(
					'loop_start',
					function ( $query ) use ( $print ) {
						if ( $query instanceof \WP_Query && $query->is_main_query() ) {
							$print();
						}
					}
				);
			} else {
				// No loop is coming, so no action inside the page will fire.
				// The page is buffered and the bar lands right after the header
				// closes — the position a person means by "the top of the page".
				//
				// The bar is rendered HERE, at template_redirect, and only the
				// finished string travels into the buffer callback. Rendering
				// inside the callback is a fatal: PHP forbids ob_start() within
				// an output-buffer handler, and the surface templates buffer.
				// The first version made exactly that mistake, and it hid
				// behind the render cache — every test hit a warm transient and
				// returned a string without templating. The first cache miss
				// after a version bump white-screened the whole site.
				add_action(
					'template_redirect',
					function () use ( $emit ) {
						$bar = $emit();

						if ( '' === $bar ) {
							return;
						}

						ob_start(
							function ( $html ) use ( $bar ) {
								if ( ! is_string( $html ) ) {
									return $html;
								}

								$pos = stripos( $html, '</header>' );
								if ( false !== $pos ) {
									return substr_replace( $html, $bar, $pos + 9, 0 );
								}

								if ( preg_match( '/<body[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
									$at = $m[0][1] + strlen( $m[0][0] );
									return substr_replace( $html, $bar, $at, 0 );
								}

								return $html . $bar;
							}
						);
					},
					1
				);

				// The buffer callback runs at shutdown, after get_footer — a
				// footer fallback would beat it to the $done guard and put the
				// bar at the bottom. This branch relies on the buffer alone.
				return;
			}
		}

		// The last resort, on every branch: an anchor that never fired by the
		// time the footer loads means a template this ladder misjudged, and a
		// bar at the end of the content beats a bar nowhere. The $done guard
		// makes this a no-op whenever anything above already rendered.
		add_action( 'get_footer', $print );
	}

	/**
	 * Does anything render on this request?
	 *
	 * @return bool
	 */
	public static function anything() {
		return self::$anything;
	}

	/**
	 * Surfaces rendering on this request.
	 *
	 * @return string[]
	 */
	public static function surfaces() {
		return array_keys( self::$surfaces );
	}

	/**
	 * Ask for a surface's assets even though no placement hooked it — a
	 * shortcode, a block or an Elementor widget on a page the router skipped.
	 *
	 * @param string $id Surface id.
	 */
	public static function need( $id ) {
		self::$surfaces[ $id ] = true;
		self::$anything        = true;
	}

	/**
	 * The request, reduced to what the placement rules need.
	 *
	 * @return array
	 */
	public static function context() {
		$product_id = 0;
		$terms      = array();

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = (int) get_queried_object_id();
			$terms      = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		} elseif ( function_exists( 'is_product_category' ) && ( is_product_category() || is_product_tag() ) ) {
			$terms = array( (int) get_queried_object_id() );
		}

		return array(
			'is_front'   => is_front_page(),
			'is_shop'    => function_exists( 'is_shop' ) && is_shop(),
			'is_product' => (bool) $product_id,
			'post_id'    => is_singular() ? (int) get_queried_object_id() : 0,
			'product_id' => $product_id,
			'term_ids'   => is_wp_error( $terms ) ? array() : array_map( 'intval', (array) $terms ),
		);
	}

	/**
	 * Render one placement, from cache when we can.
	 *
	 * The markup carries no nonce and nothing per-visitor, so one rendered bar
	 * is correct for everyone who sees that page. The cache key holds a version
	 * stamp that any story edit bumps, which is both simpler and more reliable
	 * than working out which bars a given story appears in.
	 *
	 * @param array $placement Placement.
	 * @param array $context   Request context.
	 * @return string
	 */
	public static function render( array $placement, array $context ) {
		$surface = SurfaceManager::get( $placement['surface'] );

		if ( ! $surface ) {
			return '';
		}

		// OCS_VERSION is part of the key on purpose. Without it, an update that
		// changes what a bar renders — or what the payload carries — keeps
		// serving yesterday's HTML for up to twelve hours after the deploy,
		// and the new code looks broken while it is in fact never running.
		$key = 'ocs_bar_' . md5(
			wp_json_encode(
				array(
					$placement,
					Story::version(),
					OCS_VERSION,
					'tagged' === $placement['stories']['mode'] ? $context['product_id'] : 0,
					is_rtl(),
				)
			)
		);

		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$stories = self::stories_for( $placement, $context );
		$html    = $stories ? $surface->render( $stories, $placement ) : '';

		set_transient( $key, $html, 12 * HOUR_IN_SECONDS );

		return $html;
	}

	/**
	 * Which stories this placement shows.
	 *
	 * @param array $placement Placement.
	 * @param array $context   Request context.
	 * @return array
	 */
	protected static function stories_for( array $placement, array $context ) {
		$mode  = $placement['stories']['mode'];
		$limit = max( (int) $placement['desktop']['max'], (int) $placement['mobile']['max'] );

		if ( 'collection' === $mode && '' !== (string) $placement['stories']['collection'] ) {
			$posts = Story::published(
				array(
					'limit'      => $limit,
					'collection' => (string) $placement['stories']['collection'],
				)
			);
		} elseif ( 'selected' === $mode ) {
			$posts = Story::published(
				array(
					'limit'   => $limit,
					'include' => $placement['stories']['ids'],
				)
			);
		} elseif ( 'tagged' === $mode ) {
			$ids = $context['product_id'] ? Story::for_product( $context['product_id'], $limit ) : array();

			if ( ! $ids ) {
				return array();
			}

			$posts = Story::published(
				array(
					'limit'   => $limit,
					'include' => $ids,
				)
			);
		} else {
			$posts = Story::published( array( 'limit' => $limit ) );
		}

		return $posts ? Story::to_array_many( $posts ) : array();
	}
}
