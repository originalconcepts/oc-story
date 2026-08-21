<?php
/**
 * The block editor.
 *
 * @package OC_Story
 */

namespace OCS\Display;

use OCS\Model\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * A dynamic block, so the bar a page shows is always current.
 *
 * Rendering at save time would freeze the stories, the prices and the posters
 * into post content, and the whole point of resolving products on read is that
 * they change. The editor shows a placeholder rather than a live preview for the
 * same reason: a preview that fetched real video would be slower and less honest
 * than one that says what it is.
 */
class Block {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the block and its editor script.
	 */
	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'ocs-block',
			OCS_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			OCS_VERSION,
			true
		);

		register_block_type(
			'oc-story/stories',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ocs-block',
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'placement' => array(
						'type'    => 'string',
						'default' => '',
					),
					'ids'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'size'      => array(
						'type'    => 'number',
						'default' => 0,
					),
					'labels'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * Render on the front end.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		$placement = ! empty( $attributes['placement'] ) ? Placement::get( $attributes['placement'] ) : null;

		// Same rule as the shortcode: a draft stays a draft wherever it is
		// dropped, or the screen that saved it told a lie.
		if ( $placement && empty( $placement['enabled'] ) ) {
			return '';
		}

		if ( ! $placement ) {
			$placement            = Placement::defaults();
			$placement['id']      = 'block';
			$placement['surface'] = 'circles';
		}

		$placement['hook']           = 'manual';
		$placement['where']['scope'] = 'site';

		if ( ! empty( $attributes['ids'] ) ) {
			$placement['stories']['mode'] = 'selected';
			$placement['stories']['ids']  = array_map( 'absint', explode( ',', (string) $attributes['ids'] ) );
		}

		foreach ( array( 'desktop', 'mobile' ) as $device ) {
			if ( ! empty( $attributes['size'] ) ) {
				$placement[ $device ]['size'] = (int) $attributes['size'];
			}
			if ( isset( $attributes['labels'] ) ) {
				$placement[ $device ]['labels'] = (bool) $attributes['labels'];
			}
		}

		return Injector::render( Placement::sanitize( $placement ), Injector::context() );
	}
}
