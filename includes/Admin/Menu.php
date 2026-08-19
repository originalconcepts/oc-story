<?php
/**
 * Admin menu.
 *
 * @package OC_Story
 */

namespace OCS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One top-level menu. The studio is the plugin as far as the shop owner is
 * concerned, so it is the landing page rather than something under a submenu.
 */
class Menu {

	const SLUG = 'oc-story';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	/**
	 * Register the menu and its pages.
	 */
	public function register() {
		$studio = new Studio();

		$hook = add_menu_page(
			__( 'OC Story', 'oc-story' ),
			__( 'OC Story', 'oc-story' ),
			'manage_woocommerce',
			self::SLUG,
			array( $studio, 'render' ),
			'dashicons-format-video',
			56
		);

		add_submenu_page(
			self::SLUG,
			__( 'Studio', 'oc-story' ),
			__( 'Studio', 'oc-story' ),
			'manage_woocommerce',
			self::SLUG,
			array( $studio, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $studio, 'on_load' ) );
		}
	}
}
