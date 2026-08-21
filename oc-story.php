<?php
/**
 * Plugin Name:       OC Story
 * Plugin URI:        https://originalconcepts.co.il/oc-story
 * Description:       Shoppable video and stories for WooCommerce. Instagram-style circles, sliders and product-page video, with tagged products, revenue attribution and a mobile studio.
 * Version:           0.9.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Original Concepts
 * Author URI:        https://originalconcepts.co.il
 * Text Domain:       oc-story
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package OC_Story
 */

defined( 'ABSPATH' ) || exit;

define( 'OCS_VERSION', '0.9.0' );
define( 'OCS_FILE', __FILE__ );
define( 'OCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'OCS_URL', plugin_dir_url( __FILE__ ) );
define( 'OCS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4-ish autoloader for the OCS\ namespace.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'OCS\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, 4 ) );
		$file     = OCS_PATH . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Declare HPOS (High Performance Order Storage) compatibility.
 *
 * Revenue attribution writes to order meta, so this has to be honest.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OCS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', OCS_FILE, true );
		}
	}
);

register_activation_hook( OCS_FILE, array( \OCS\Core\Install::class, 'activate' ) );
register_deactivation_hook( OCS_FILE, array( \OCS\Core\Install::class, 'deactivate' ) );

/**
 * Auto-updates from GitHub releases. Admin only, and independent of WooCommerce
 * so updates keep working even if WooCommerce is momentarily inactive.
 */
if ( is_admin() ) {
	( new \OCS\Core\Updater( OCS_BASENAME, OCS_VERSION, 'originalconcepts/oc-story' ) )->register();
}

/**
 * Main accessor.
 *
 * @return \OCS\Core\Plugin
 */
function ocs() {
	return \OCS\Core\Plugin::instance();
}

add_action( 'plugins_loaded', 'ocs', 5 );
