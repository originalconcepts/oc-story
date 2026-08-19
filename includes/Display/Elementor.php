<?php
/**
 * Elementor integration.
 *
 * @package OC_Story
 */

namespace OCS\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the widget, and only when Elementor is actually there.
 *
 * The widget class extends an Elementor base class, so its file is required
 * inside the hook rather than reached through the autoloader — otherwise a shop
 * that removes Elementor gets a fatal error on the next request instead of a
 * missing widget.
 */
class Elementor {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'elementor/widgets/register', array( $this, 'register' ) );
	}

	/**
	 * Register the widget.
	 *
	 * @param object $widgets Elementor's widget manager.
	 */
	public function register( $widgets ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! is_object( $widgets ) || ! method_exists( $widgets, 'register' ) ) {
			return;
		}

		require_once OCS_PATH . 'includes/Display/ElementorWidget.php';

		$widgets->register( new ElementorWidget() );
	}
}
