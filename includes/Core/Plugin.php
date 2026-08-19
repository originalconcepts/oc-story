<?php
/**
 * Plugin bootstrap.
 *
 * @package OC_Story
 */

namespace OCS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every module together. Deliberately thin: it only decides what loads,
 * never what anything does.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether WooCommerce was found.
	 *
	 * @var bool
	 */
	private $wc_ready = false;

	/**
	 * Instantiated modules, keyed by short name.
	 *
	 * @var array<string,object>
	 */
	private $modules = array();

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		load_plugin_textdomain( 'oc-story', false, dirname( OCS_BASENAME ) . '/languages' );

		$this->wc_ready = class_exists( 'WooCommerce' );

		if ( ! $this->wc_ready ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		Install::maybe_upgrade();

		$this->boot();
	}

	/**
	 * Instantiate modules.
	 *
	 * Entries are added as each milestone lands; `class_exists` keeps a partial
	 * tree booting cleanly during development.
	 */
	private function boot() {
		$map = array(
			// Data layer.
			'story_hooks' => \OCS\Model\StoryHooks::class,

			// Display (milestones 4-6).
			'assets'      => \OCS\Display\Assets::class,
			'injector'    => \OCS\Display\Injector::class,
			'shortcode'   => \OCS\Display\Shortcode::class,

			// REST (milestones 2-7).
			'rest'        => \OCS\Rest\Routes::class,
		);

		if ( is_admin() ) {
			$map['admin'] = \OCS\Admin\Menu::class;
		}

		foreach ( $map as $key => $class ) {
			if ( class_exists( $class ) ) {
				$this->modules[ $key ] = new $class();
			}
		}

		do_action( 'ocs_loaded', $this );
	}

	/**
	 * Fetch a booted module.
	 *
	 * @param string $key Module key.
	 * @return object|null
	 */
	public function module( $key ) {
		return isset( $this->modules[ $key ] ) ? $this->modules[ $key ] : null;
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'OC Story requires WooCommerce to be installed and active.', 'oc-story' );
		echo '</p></div>';
	}
}
