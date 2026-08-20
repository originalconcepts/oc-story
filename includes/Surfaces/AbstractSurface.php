<?php
/**
 * Shared surface behaviour.
 *
 * @package OC_Story
 */

namespace OCS\Surfaces;

defined( 'ABSPATH' ) || exit;

/**
 * Template resolution and the player payload.
 *
 * Everything a surface emits is rendered server-side into the page body. No
 * surface fetches anything on load, because the bar is above the fold on a home
 * page: an AJAX bar is the LCP element arriving one round trip late, and it
 * defeats full-page caching for nothing in return.
 */
abstract class AbstractSurface implements SurfaceInterface {

	/**
	 * Above this many bytes the payload is fetched on first tap instead of
	 * being inlined.
	 *
	 * Inlining costs every visitor the bytes; fetching costs one visitor in
	 * twenty a round trip they never notice, because the fetch starts on
	 * `pointerdown` — before the tap has finished. Small bars inline, large
	 * bars do not, and the budget in PLAN.md §10 holds either way.
	 */
	const INLINE_LIMIT = 8192;

	/**
	 * Find a template, preferring one the theme has overridden.
	 *
	 * @param string $name Template file below `surfaces/`.
	 * @return string Absolute path.
	 */
	protected function locate_template( $name ) {
		$found = locate_template( array( 'oc-story/' . $name ) );

		if ( $found ) {
			return $found;
		}

		return OCS_PATH . 'templates/surfaces/' . $name;
	}

	/**
	 * Render a template with variables in scope.
	 *
	 * @param string $name Template file below `surfaces/`.
	 * @param array  $vars Variables.
	 * @return string
	 */
	protected function template( $name, array $vars ) {
		$file = $this->locate_template( $name );

		if ( ! is_readable( $file ) ) {
			return '';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		include $file;

		return (string) ob_get_clean();
	}

	/**
	 * The compact payload the player reads.
	 *
	 * Short keys on purpose: this travels in the HTML of every page the bar
	 * appears on, and `poster_url` repeated forty times is a real number.
	 *
	 * @param array $stories Stories from Model\Story::to_array().
	 * @return array
	 */
	public function payload( array $stories ) {
		return \OCS\Model\Story::to_payload( $stories );
	}

	/**
	 * Emit the payload, inline when it is small enough to be free.
	 *
	 * @param array  $stories Stories.
	 * @param string $key     Placement id.
	 * @return string
	 */
	protected function payload_tag( array $stories, $key ) {
		$json = wp_json_encode( $this->payload( $stories ) );

		if ( ! is_string( $json ) ) {
			return '';
		}

		if ( strlen( $json ) > (int) apply_filters( 'ocs_inline_payload_limit', self::INLINE_LIMIT ) ) {
			return '';
		}

		// A JSON script type is left alone by every minifier worth the name;
		// anything else gets mangled.
		return '<script type="application/json" id="ocs-data-' . esc_attr( $key ) . '">' . $json . '</script>';
	}

	/**
	 * Where the player fetches from when the payload was not inlined.
	 *
	 * @param array $stories Stories.
	 * @return string
	 */
	protected function payload_src( array $stories ) {
		$ids = array();
		foreach ( $stories as $story ) {
			$ids[] = (int) $story['id'];
		}

		return add_query_arg( 'ids', implode( ',', $ids ), rest_url( 'oc-story/v1/stories' ) );
	}

	/**
	 * Stories with something playable in them.
	 *
	 * A circle or a card that opens onto nothing is worse than a shorter row,
	 * so a story whose every slide has lost its file is dropped here rather
	 * than rendered and discovered on tap.
	 *
	 * @param array $stories Stories.
	 * @return array
	 */
	protected function playable( array $stories ) {
		$keep = array();

		foreach ( $this->payload( $stories ) as $story ) {
			$keep[] = (int) $story['i'];
		}

		$out = array();
		foreach ( $stories as $story ) {
			if ( in_array( (int) $story['id'], $keep, true ) ) {
				$out[] = $story;
			}
		}

		return $out;
	}

	/**
	 * Card sizing for the slider surfaces.
	 *
	 * The placement's size means a circle's diameter for the bar and a card's
	 * width here, so the same two numbers keep meaning "how big, on each kind
	 * of screen" without a second pair of settings to explain.
	 *
	 * @param array $placement Placement.
	 * @return string
	 */
	protected function card_vars( array $placement ) {
		// The size is the card width. Values under 100 predate that meaning —
		// they were circle diameters reused — and are doubled to keep old
		// widgets looking as they did.
		$desktop = (int) $placement['desktop']['size'];
		$mobile  = (int) $placement['mobile']['size'];

		return sprintf(
			'--ocs-card:%dpx;--ocs-card-mobile:%dpx;--ocs-align:%s;--ocs-align-mobile:%s',
			$desktop < 100 ? $desktop * 2 : $desktop,
			$mobile < 100 ? $mobile * 2 : $mobile,
			'start' === $placement['desktop']['align'] ? 'flex-start' : ( 'end' === $placement['desktop']['align'] ? 'flex-end' : 'center' ),
			'start' === $placement['mobile']['align'] ? 'flex-start' : ( 'end' === $placement['mobile']['align'] ? 'flex-end' : 'center' )
		);
	}

	/**
	 * Per-device layout as custom properties, so one markup serves everyone.
	 *
	 * Desktop and mobile ship together and CSS picks. Branching in PHP would
	 * mean two cache entries for one page, or a bar that is wrong for half the
	 * visitors behind a full-page cache.
	 *
	 * @param array $placement Placement.
	 * @return string
	 */
	protected function style_vars( array $placement ) {
		return sprintf(
			'--ocs-size:%dpx;--ocs-size-mobile:%dpx;--ocs-align:%s;--ocs-align-mobile:%s',
			(int) $placement['desktop']['size'],
			(int) $placement['mobile']['size'],
			'start' === $placement['desktop']['align'] ? 'flex-start' : ( 'end' === $placement['desktop']['align'] ? 'flex-end' : 'center' ),
			'start' === $placement['mobile']['align'] ? 'flex-start' : ( 'end' === $placement['mobile']['align'] ? 'flex-end' : 'center' )
		);
	}
}
