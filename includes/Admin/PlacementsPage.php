<?php
/**
 * The placements screen.
 *
 * @package OC_Story
 */

namespace OCS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Where the videos show up.
 *
 * The rules engine behind this has existed since the first milestone and is
 * covered by the test harness; what was missing was a way for the person paying
 * for the plugin to reach it without editing an option by hand.
 */
class PlacementsPage {

	const SLUG   = 'oc-story-placements';
	const HANDLE = 'ocs-placements';

	/**
	 * Runs only when this screen loads.
	 */
	public function on_load() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Assets.
	 */
	public function enqueue() {
		wp_enqueue_style( 'ocs-studio', OCS_URL . 'assets/css/studio.css', array(), OCS_VERSION );

		wp_register_script( self::HANDLE, OCS_URL . 'assets/js/placements.js', array(), OCS_VERSION, true );

		wp_localize_script(
			self::HANDLE,
			'ocsPlacements',
			array(
				'api'  => array(
					'root'  => esc_url_raw( rest_url( 'oc-story/v1' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				),
				'i18n' => array(
					'title'        => __( 'Video widgets', 'oc-story' ),
					'add'          => __( 'Add a widget', 'oc-story' ),
					'empty'        => __( 'No widgets yet', 'oc-story' ),
					'emptyHint'    => __( 'Each widget is its own row of videos: choose how it looks, which pages it lives on, and which videos it shows.', 'oc-story' ),
					'name'         => __( 'Widget name', 'oc-story' ),
					'namePlaceholder' => __( 'Influencers on the home page', 'oc-story' ),
					'surface'      => __( 'How it looks', 'oc-story' ),
					'scope'        => __( 'Which pages', 'oc-story' ),
					'position'     => __( 'Where on the page', 'oc-story' ),
					'choosePages'  => __( 'Pages', 'oc-story' ),
					'chooseProducts' => __( 'Products', 'oc-story' ),
					'chooseTerms'  => __( 'Categories', 'oc-story' ),
					'search'       => __( 'Start typing…', 'oc-story' ),
					'whichStories' => __( 'Which videos', 'oc-story' ),
					'modeAll'      => __( 'All of them', 'oc-story' ),
					'modeSelected' => __( 'Only the ones I choose', 'oc-story' ),
					'modeCollection' => __( 'A collection', 'oc-story' ),
					'collection'   => __( 'Collection', 'oc-story' ),
					'modeTagged'   => __( 'The ones tagged to the product on screen', 'oc-story' ),
					'desktop'      => __( 'On a computer', 'oc-story' ),
					'mobile'       => __( 'On a phone', 'oc-story' ),
					'show'         => __( 'Show it', 'oc-story' ),
					'size'         => __( 'Circle size', 'oc-story' ),
					'labels'       => __( 'Caption under the circle', 'oc-story' ),
					'align'        => __( 'Alignment', 'oc-story' ),
					'alignStart'   => __( 'Start', 'oc-story' ),
					'alignCenter'  => __( 'Centre', 'oc-story' ),
					'alignEnd'     => __( 'End', 'oc-story' ),
					'max'          => __( 'How many at most', 'oc-story' ),
					'enabled'      => __( 'Active', 'oc-story' ),
					'remove'       => __( 'Remove', 'oc-story' ),
					'save'         => __( 'Save', 'oc-story' ),
					'saving'       => __( 'Saving…', 'oc-story' ),
					'saved'        => __( 'Saved', 'oc-story' ),
					'failed'       => __( 'That did not work', 'oc-story' ),
					'manualHint'   => __( 'Use the shortcode [oc_story] or the OC Story block wherever you want it.', 'oc-story' ),
				),
			)
		);

		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Render the root.
	 */
	public function render() {
		echo '<div class="wrap ocs-wrap"><div id="ocs-placements" class="ocs-app" data-loading="1">';
		echo '<div class="ocs-boot">' . esc_html__( 'Loading…', 'oc-story' ) . '</div>';
		echo '</div></div>';
	}
}
