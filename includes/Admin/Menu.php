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
		// One screen where there were two. A gallery used to be made in the
		// studio and shown by a widget, and the link between them was the
		// hardest idea in the plugin — hard enough that its own author built
		// it twice and saw nothing appear. Now there is a list of galleries
		// and a way to make one.
		$galleries = new WizardPage();

		$hook = add_menu_page(
			__( 'OC Story', 'oc-story' ),
			__( 'OC Story', 'oc-story' ),
			'manage_woocommerce',
			self::SLUG,
			array( $galleries, 'render' ),
			'dashicons-format-video',
			56
		);

		add_submenu_page(
			self::SLUG,
			__( 'Galleries', 'oc-story' ),
			__( 'Galleries', 'oc-story' ),
			'manage_woocommerce',
			self::SLUG,
			array( $galleries, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $galleries, 'on_load' ) );
		}

		// The old widgets screen, reachable by its address and absent from the
		// menu. It is the only place the per-device sizes can still be edited
		// until the gallery gets its own settings drawer; it goes the moment
		// that lands. A shop owner will never find it, which is the point.
		$placements = new PlacementsPage();

		$placements_hook = add_submenu_page(
			'',
			__( 'Widgets', 'oc-story' ),
			__( 'Widgets', 'oc-story' ),
			'manage_woocommerce',
			PlacementsPage::SLUG,
			array( $placements, 'render' )
		);

		if ( $placements_hook ) {
			add_action( 'load-' . $placements_hook, array( $placements, 'on_load' ) );
		}

		add_submenu_page(
			self::SLUG,
			__( 'Insights', 'oc-story' ),
			__( 'Insights', 'oc-story' ),
			'manage_woocommerce',
			InsightsPage::SLUG,
			array( new InsightsPage(), 'render' )
		);

		$settings = new SettingsPage();

		$settings_hook = add_submenu_page(
			self::SLUG,
			__( 'Settings', 'oc-story' ),
			__( 'Settings', 'oc-story' ),
			'manage_woocommerce',
			SettingsPage::SLUG,
			array( $settings, 'render' )
		);

		if ( $settings_hook ) {
			add_action( 'load-' . $settings_hook, array( $settings, 'on_load' ) );
		}
	}
}
