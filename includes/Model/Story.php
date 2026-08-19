<?php
/**
 * Stories: the post type, its slides, and the reverse product index.
 *
 * @package OC_Story
 */

namespace OCS\Model;

use OCS\Core\Install;

defined( 'ABSPATH' ) || exit;

/**
 * A story is one circle. It holds an ordered list of slides; each slide is one
 * video and the products tagged on it.
 *
 * The post type is deliberately invisible: `public => false`, `show_ui => false`.
 * We render our own studio, and a public single view of a story has no meaning.
 * What we keep from the CPT is post status, `menu_order` for the ordering of the
 * circles, and WP_Query priming meta for the whole bar in one pass.
 *
 * Slides live in one JSON meta key. One `get_post_meta()` renders a story — no
 * meta_query, no join, no table.
 */
class Story {

	const POST_TYPE  = 'oc_story';
	const META_SLIDES = '_ocs_slides';
	const META_LABEL  = '_ocs_label';

	/**
	 * Register the post type. Called on `init`.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Stories', 'oc-story' ),
					'singular_name' => __( 'Story', 'oc-story' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'page-attributes', 'thumbnail' ),
			)
		);
	}

	/**
	 * Slides for a story, normalised.
	 *
	 * @param int $story_id Story post ID.
	 * @return array<int,array>
	 */
	public static function slides( $story_id ) {
		$raw = get_post_meta( (int) $story_id, self::META_SLIDES, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		return self::normalize_slides( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Replace the slides on a story and rebuild its product index.
	 *
	 * @param int   $story_id Story post ID.
	 * @param array $slides   Raw slides.
	 * @return array<int,array> The normalised slides that were stored.
	 */
	public static function set_slides( $story_id, array $slides ) {
		$story_id = (int) $story_id;
		$clean    = self::normalize_slides( $slides );

		update_post_meta( $story_id, self::META_SLIDES, wp_json_encode( $clean ) );
		self::sync_index( $story_id, $clean );

		// The circle needs an image and the owner has not been asked for one, so
		// the first slide's poster becomes it. Only when there is none already —
		// a deliberate choice must survive the next edit.
		if ( ! empty( $clean[0]['poster'] ) && ! get_post_thumbnail_id( $story_id ) ) {
			set_post_thumbnail( $story_id, (int) $clean[0]['poster'] );
		}

		self::bump_version();

		do_action( 'ocs_story_updated', $story_id, $clean );

		return $clean;
	}

	/**
	 * Coerce arbitrary input into the slide shape documented in PLAN.md §3.
	 *
	 * Pure logic, no WordPress: the test harness leans on this, and so does
	 * every REST write. Anything unrecognised is dropped rather than stored —
	 * a slide with no playable reference is worse than no slide.
	 *
	 * @param array $slides Raw slides.
	 * @return array<int,array>
	 */
	public static function normalize_slides( array $slides ) {
		$out  = array();
		$seen = array();

		foreach ( $slides as $slide ) {
			if ( ! is_array( $slide ) ) {
				continue;
			}

			$ref = isset( $slide['ref'] ) ? (string) $slide['ref'] : '';
			if ( '' === $ref ) {
				continue;
			}

			$id = isset( $slide['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $slide['id'] ) ) : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = self::new_slide_id();
			}
			$seen[ $id ] = true;

			$width  = isset( $slide['w'] ) ? (int) $slide['w'] : 0;
			$height = isset( $slide['h'] ) ? (int) $slide['h'] : 0;

			$duration = isset( $slide['duration'] ) ? round( (float) $slide['duration'], 2 ) : 0.0;
			if ( $duration < 0 ) {
				$duration = 0.0;
			}

			$out[] = array(
				'id'       => $id,
				'source'   => isset( $slide['source'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $slide['source'] ) ) : 'local',
				'ref'      => $ref,
				'poster'   => isset( $slide['poster'] ) ? (int) $slide['poster'] : 0,
				'w'        => $width > 0 ? $width : 0,
				'h'        => $height > 0 ? $height : 0,
				'duration' => $duration,
				'products' => self::normalize_products( isset( $slide['products'] ) ? $slide['products'] : array() ),
				'cta'      => self::normalize_cta( isset( $slide['cta'] ) ? $slide['cta'] : array() ),
			);
		}

		return $out;
	}

	/**
	 * Tagged products on one slide.
	 *
	 * Coordinates are 0..1 fractions of the frame, or null when the product is
	 * only in the strip and not pinned to a point in the video.
	 *
	 * @param mixed $products Raw products.
	 * @return array<int,array>
	 */
	protected static function normalize_products( $products ) {
		if ( ! is_array( $products ) ) {
			return array();
		}

		$out  = array();
		$seen = array();

		foreach ( $products as $product ) {
			// Accept a bare id as well as the full shape; the studio sends the
			// full shape but hand-written filters will not.
			if ( is_scalar( $product ) ) {
				$product = array( 'id' => $product );
			}
			if ( ! is_array( $product ) ) {
				continue;
			}

			$id = isset( $product['id'] ) ? absint( $product['id'] ) : 0;
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$out[] = array(
				'id' => $id,
				'x'  => self::normalize_coord( isset( $product['x'] ) ? $product['x'] : null ),
				'y'  => self::normalize_coord( isset( $product['y'] ) ? $product['y'] : null ),
			);
		}

		return $out;
	}

	/**
	 * One 0..1 coordinate, or null.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	protected static function normalize_coord( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$value = (float) $value;
		if ( $value < 0 ) {
			$value = 0.0;
		}
		if ( $value > 1 ) {
			$value = 1.0;
		}
		return round( $value, 4 );
	}

	/**
	 * Optional call to action overriding the product link.
	 *
	 * @param mixed $cta Raw CTA.
	 * @return array
	 */
	protected static function normalize_cta( $cta ) {
		if ( ! is_array( $cta ) ) {
			return array(
				'text' => '',
				'url'  => '',
			);
		}

		$text = isset( $cta['text'] ) ? trim( wp_strip_all_tags( (string) $cta['text'] ) ) : '';
		$url  = isset( $cta['url'] ) ? esc_url_raw( (string) $cta['url'] ) : '';

		return array(
			'text' => mb_substr( $text, 0, 40 ),
			'url'  => $url,
		);
	}

	/**
	 * A short, collision-resistant slide id.
	 *
	 * Stats rows reference it for the lifetime of the story, so it must survive
	 * reordering and must never be an array index.
	 *
	 * @return string
	 */
	public static function new_slide_id() {
		return 's_' . substr( md5( uniqid( 'ocs', true ) ), 0, 8 );
	}

	/**
	 * Rebuild the reverse product index for one story.
	 *
	 * Delete-then-insert rather than a diff: a story has a handful of slides and
	 * a handful of products, and correctness here is worth more than the write.
	 *
	 * @param int   $story_id Story post ID.
	 * @param array $slides   Normalised slides.
	 */
	public static function sync_index( $story_id, array $slides ) {
		global $wpdb;

		$table    = Install::table( 'slide_product' );
		$story_id = (int) $story_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'story_id' => $story_id ), array( '%d' ) );

		$sort = 0;
		foreach ( $slides as $slide ) {
			foreach ( $slide['products'] as $product ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$table,
					array(
						'story_id'   => $story_id,
						'slide_id'   => $slide['id'],
						'product_id' => $product['id'],
						'sort'       => $sort,
					),
					array( '%d', '%s', '%d', '%d' )
				);
				++$sort;
			}
		}
	}

	/**
	 * Drop a story's index rows. Called when the post is deleted.
	 *
	 * @param int $story_id Story post ID.
	 */
	public static function clear_index( $story_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Install::table( 'slide_product' ), array( 'story_id' => (int) $story_id ), array( '%d' ) );
	}

	/**
	 * Story IDs that tag a given product, in publish order.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit      Maximum stories.
	 * @return int[]
	 */
	public static function for_product( $product_id, $limit = 10 ) {
		global $wpdb;

		$table = Install::table( 'slide_product' );
		$posts = $wpdb->posts;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.story_id
				 FROM {$table} m
				 INNER JOIN {$posts} p ON p.ID = m.story_id
				 WHERE m.product_id = %d
				   AND p.post_type = %s
				   AND p.post_status = 'publish'
				 ORDER BY p.menu_order ASC, p.post_date DESC
				 LIMIT %d",
				(int) $product_id,
				self::POST_TYPE,
				max( 1, (int) $limit )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Create a story.
	 *
	 * @param array $args Keys: title, status, slides.
	 * @return int|\WP_Error Story ID.
	 */
	public static function create( array $args = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => self::clean_title( isset( $args['title'] ) ? $args['title'] : '' ),
				'post_status' => self::clean_status( isset( $args['status'] ) ? $args['status'] : 'draft' ),
				'menu_order'  => self::next_order(),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( isset( $args['slides'] ) && is_array( $args['slides'] ) ) {
			self::set_slides( $id, $args['slides'] );
		}

		return (int) $id;
	}

	/**
	 * Update a story. Only the keys present are touched.
	 *
	 * @param int   $story_id Story ID.
	 * @param array $args     Keys: title, status, slides, poster.
	 * @return int|\WP_Error
	 */
	public static function update( $story_id, array $args ) {
		$story_id = (int) $story_id;

		if ( self::POST_TYPE !== get_post_type( $story_id ) ) {
			return new \WP_Error( 'ocs_not_a_story', __( 'That story does not exist.', 'oc-story' ), array( 'status' => 404 ) );
		}

		$post = array( 'ID' => $story_id );

		if ( array_key_exists( 'title', $args ) ) {
			$post['post_title'] = self::clean_title( $args['title'] );
		}
		if ( array_key_exists( 'status', $args ) ) {
			$post['post_status'] = self::clean_status( $args['status'] );
		}

		if ( count( $post ) > 1 ) {
			$result = wp_update_post( $post, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'slides', $args ) && is_array( $args['slides'] ) ) {
			self::set_slides( $story_id, $args['slides'] );
		}

		self::bump_version();

		if ( array_key_exists( 'poster', $args ) ) {
			$poster = (int) $args['poster'];
			if ( $poster ) {
				set_post_thumbnail( $story_id, $poster );
			} else {
				delete_post_thumbnail( $story_id );
			}
		}

		return $story_id;
	}

	/**
	 * Several stories at once, resolving every product in a single query.
	 *
	 * The rule that governs display code: never look a product up inside a
	 * loop. A bar with twelve circles must not become twelve queries.
	 *
	 * @param \WP_Post[] $posts Story posts.
	 * @return array<int,array>
	 */
	public static function to_array_many( array $posts ) {
		$slides = array();
		$ids    = array();

		foreach ( $posts as $post ) {
			$slides[ $post->ID ] = self::slides( $post->ID );
			foreach ( $slides[ $post->ID ] as $slide ) {
				foreach ( $slide['products'] as $product ) {
					$ids[] = $product['id'];
				}
			}
		}

		$catalogue = Products::summaries( $ids );

		$out = array();
		foreach ( $posts as $post ) {
			$story = self::assemble( $post, $slides[ $post->ID ], $catalogue );
			if ( $story ) {
				$out[] = $story;
			}
		}

		return $out;
	}

	/**
	 * A story as the studio and the player want it: slides with playback URLs
	 * and products resolved, and never a stored price.
	 *
	 * @param int|\WP_Post $story Story ID or post.
	 * @return array|null
	 */
	public static function to_array( $story ) {
		$post = get_post( $story );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$slides = self::slides( $post->ID );

		$ids = array();
		foreach ( $slides as $slide ) {
			foreach ( $slide['products'] as $product ) {
				$ids[] = $product['id'];
			}
		}

		return self::assemble( $post, $slides, Products::summaries( $ids ) );
	}

	/**
	 * Build the public shape from parts that have already been fetched.
	 *
	 * @param \WP_Post $post      Story post.
	 * @param array    $slides    Normalised slides.
	 * @param array    $catalogue Product summaries keyed by ID.
	 * @return array
	 */
	protected static function assemble( $post, array $slides, array $catalogue ) {
		$out = array();

		foreach ( $slides as $slide ) {
			$source = \OCS\Media\SourceManager::get( $slide['source'] );

			$products = array();
			foreach ( $slide['products'] as $product ) {
				if ( ! isset( $catalogue[ $product['id'] ] ) ) {
					continue;
				}
				$products[] = array_merge(
					$catalogue[ $product['id'] ],
					array(
						'x' => $product['x'],
						'y' => $product['y'],
					)
				);
			}

			$out[] = array(
				'id'         => $slide['id'],
				'source'     => $slide['source'],
				'ref'        => $slide['ref'],
				'url'        => $source->get_playback_url( $slide['ref'] ),
				'poster'     => $slide['poster'],
				'poster_url' => $slide['poster'] ? (string) wp_get_attachment_url( $slide['poster'] ) : '',
				'w'          => $slide['w'],
				'h'          => $slide['h'],
				'duration'   => $slide['duration'],
				'products'   => $products,
				'cta'        => $slide['cta'],
			);
		}

		$thumbnail = get_post_thumbnail_id( $post->ID );

		// The circle image falls back to the first slide's poster, so a story
		// always has one without the owner being asked for it.
		$circle_id = $thumbnail ? (int) $thumbnail : ( isset( $slides[0]['poster'] ) ? (int) $slides[0]['poster'] : 0 );

		// A circle is at most 160 CSS pixels across; serving it the full
		// 540x960 poster spends ~40KB to paint ~10KB. WordPress already made
		// the smaller rendition when the poster was uploaded — 'medium' is
		// ~300px on its long edge, which covers a retina circle exactly.
		$thumb = $circle_id ? (string) wp_get_attachment_image_url( $circle_id, 'medium' ) : '';

		return array(
			'id'         => (int) $post->ID,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'menu_order' => (int) $post->menu_order,
			'poster'     => (int) $thumbnail,
			'poster_url' => $circle_id ? (string) wp_get_attachment_url( $circle_id ) : '',
			'thumb_url'  => '' !== $thumb ? $thumb : ( $circle_id ? (string) wp_get_attachment_url( $circle_id ) : '' ),
			'slides'     => $out,
		);
	}

	/**
	 * The compact shape the player reads.
	 *
	 * Short keys on purpose: this travels in the HTML of every page a bar
	 * appears on, and `poster_url` spelled out forty times is a real number.
	 * Slides with nothing playable are dropped rather than rendered as a gap.
	 *
	 * @param array $stories Stories from to_array()/to_array_many().
	 * @return array
	 */
	public static function to_payload( array $stories ) {
		$out = array();

		foreach ( $stories as $story ) {
			$slides = array();

			foreach ( $story['slides'] as $slide ) {
				if ( '' === $slide['url'] ) {
					continue;
				}

				$products = array();
				foreach ( $slide['products'] as $product ) {
					$products[] = array(
						'i' => $product['id'],
						'n' => $product['name'],
						'p' => $product['price'],
						'u' => $product['url'],
						't' => $product['thumb'],
						'x' => $product['x'],
						'y' => $product['y'],
					);
				}

				$slides[] = array(
					'i'  => $slide['id'],
					'u'  => $slide['url'],
					'p'  => $slide['poster_url'],
					'w'  => $slide['w'],
					'h'  => $slide['h'],
					'd'  => $slide['duration'],
					'pr' => $products,
				);
			}

			if ( ! $slides ) {
				continue;
			}

			$out[] = array(
				'i' => (int) $story['id'],
				't' => $story['title'],
				's' => $slides,
			);
		}

		return $out;
	}

	/**
	 * A counter that changes whenever anything a bar renders changes.
	 *
	 * Rendered bars are cached, and this is what expires them. Bumping one
	 * option is cheaper and far more reliable than trying to work out which
	 * placements a given story appears in.
	 *
	 * @return int
	 */
	public static function version() {
		return (int) get_option( 'ocs_stories_version', 1 );
	}

	/**
	 * Invalidate every cached bar.
	 */
	public static function bump_version() {
		update_option( 'ocs_stories_version', self::version() + 1, true );
	}

	/**
	 * Where a new story goes in the bar: first.
	 *
	 * New content is the reason someone opens a story bar at all, so a new story
	 * leads rather than landing at the end of the row where nobody scrolls.
	 *
	 * @return int
	 */
	protected static function next_order() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lowest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(menu_order) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
				self::POST_TYPE
			)
		);

		return null === $lowest ? 0 : ( (int) $lowest - 1 );
	}

	/**
	 * Story titles are shown under a circle, so they are short by definition.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	public static function clean_title( $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );

		return mb_substr( $title, 0, 60 );
	}

	/**
	 * Only two statuses mean anything here.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function clean_status( $status ) {
		return 'publish' === $status ? 'publish' : 'draft';
	}

	/**
	 * Apply a new order to a list of stories in one pass.
	 *
	 * @param int[] $ids Story IDs, in the order they should appear.
	 * @return int How many rows were moved.
	 */
	public static function reorder( array $ids ) {
		global $wpdb;

		$moved = 0;

		foreach ( array_values( array_filter( array_map( 'absint', $ids ) ) ) as $position => $id ) {
			if ( self::POST_TYPE !== get_post_type( $id ) ) {
				continue;
			}

			// wp_update_post() here would fire the full save cycle for every
			// card the owner dragged past. This only ever touches menu_order.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->posts, array( 'menu_order' => $position ), array( 'ID' => $id ), array( '%d' ), array( '%d' ) );
			clean_post_cache( $id );
			++$moved;
		}

		self::bump_version();

		return $moved;
	}

	/**
	 * Published stories for a surface, in bar order.
	 *
	 * @param array $args Overrides: 'limit', 'include'.
	 * @return \WP_Post[]
	 */
	public static function published( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'   => 20,
				'include' => array(),
			)
		);

		$query = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => max( 1, (int) $args['limit'] ),
			'orderby'                => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		if ( ! empty( $args['include'] ) ) {
			$query['post__in'] = array_map( 'absint', (array) $args['include'] );
			$query['orderby']  = 'post__in';
		}

		$query = apply_filters( 'ocs_story_query_args', $query, $args );

		return get_posts( $query );
	}
}
