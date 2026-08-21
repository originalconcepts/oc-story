<?php
/**
 * The Elementor widget.
 *
 * Only ever loaded from Display\Elementor, which checks that Elementor is
 * present first. Do not reach this through the autoloader.
 *
 * @package OC_Story
 */

namespace OCS\Display;

use OCS\Model\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * A row of story circles, placed by dragging.
 */
class ElementorWidget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'oc_story';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'OC Story', 'oc-story' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-slider-video';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content',
			array( 'label' => __( 'OC Story', 'oc-story' ) )
		);

		// Pick a gallery that already exists, which is what the wizard tells
		// people this widget is for. Everything below it is the older way of
		// describing a bar by hand, kept for anyone already using it.
		$choices = array( '' => __( '— Build one here —', 'oc-story' ) );

		foreach ( Placement::all() as $saved ) {
			$choices[ $saved['id'] ] = $saved['label'] ? $saved['label'] : $saved['id'];
		}

		$this->add_control(
			'placement',
			array(
				'label'       => __( 'Gallery', 'oc-story' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $choices,
				'default'     => '',
				'description' => __( 'A gallery made in OC Story → Galleries.', 'oc-story' ),
			)
		);

		$this->add_control(
			'ids',
			array(
				'label'       => __( 'Only these stories (IDs, comma separated)', 'oc-story' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'Leave empty to show them all.', 'oc-story' ),
			)
		);

		$this->add_responsive_control(
			'size',
			array(
				'label'      => __( 'Circle size', 'oc-story' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'range'      => array( 'px' => array( 'min' => 40, 'max' => 160 ) ),
				'default'    => array( 'size' => 84 ),
			)
		);

		$this->add_control(
			'labels',
			array(
				'label'        => __( 'Caption under the circle', 'oc-story' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$chosen = ! empty( $settings['placement'] ) ? Placement::get( (string) $settings['placement'] ) : null;

		// A draft is a draft wherever it is dropped.
		if ( $chosen && empty( $chosen['enabled'] ) ) {
			return;
		}

		if ( $chosen ) {
			$placement = $chosen;
		} else {
			$placement            = Placement::defaults();
			$placement['id']      = 'elementor-' . $this->get_id();
			$placement['surface'] = 'circles';
		}

		$placement['hook']           = 'manual';
		$placement['where']['scope'] = 'site';

		if ( ! empty( $settings['ids'] ) ) {
			$placement['stories']['mode'] = 'selected';
			$placement['stories']['ids']  = array_map( 'absint', explode( ',', (string) $settings['ids'] ) );
		}

		$size = isset( $settings['size']['size'] ) ? (int) $settings['size']['size'] : 0;

		foreach ( array( 'desktop', 'mobile' ) as $device ) {
			if ( $size ) {
				$placement[ $device ]['size'] = $size;
			}
			$placement[ $device ]['labels'] = 'yes' === ( isset( $settings['labels'] ) ? $settings['labels'] : 'yes' );
		}

		// A widget can sit on a page the injector already skipped.
		add_filter( 'ocs_force_assets', '__return_true' );
		wp_enqueue_style( 'ocs-bar' );
		wp_enqueue_script( 'ocs-bar' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Injector::render( Placement::sanitize( $placement ), Injector::context() );
	}
}
