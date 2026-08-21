<?php
/**
 * Galleries: the list, and the three questions that make a new one.
 *
 * @package OC_Story
 */

namespace OCS\Admin;

use OCS\Core\Settings;
use OCS\Media\Probe;
use OCS\Model\Positions;

defined( 'ABSPATH' ) || exit;

/**
 * The screen that replaced two screens.
 *
 * Everything the wizard offers comes from `Positions`, printed into the page
 * once. The alternative — the browser asking what each branch offers as the
 * person walks through it — would put a round trip between "product pages"
 * and seeing where a gallery can go on one, and the whole point of the
 * pictures is that they answer instantly.
 */
class WizardPage {

	const SLUG   = 'oc-story';
	const HANDLE = 'ocs-wizard';

	/**
	 * Runs only when our screen is being loaded.
	 *
	 * Hooking the enqueue from here rather than from the constructor is what
	 * keeps this screen's JavaScript off every other page of wp-admin.
	 */
	public function on_load() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Mark the screen so the stylesheet can take over the layout.
	 *
	 * @param string $classes Existing classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		return $classes . ' ocs-studio-screen';
	}

	/**
	 * Assets.
	 */
	public function enqueue() {
		wp_enqueue_style( 'ocs-studio', OCS_URL . 'assets/css/studio.css', array(), OCS_VERSION );

		wp_register_script( self::HANDLE, OCS_URL . 'assets/js/wizard.js', array(), OCS_VERSION, true );

		wp_localize_script(
			self::HANDLE,
			'ocsWizard',
			array(
				'api'       => array(
					'root'  => esc_url_raw( rest_url( 'oc-story/v1' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				),
				// The video editor is imported on demand, the first time
				// someone reaches step three. Most visits to this screen are
				// to look at a list.
				'studio'    => OCS_URL . 'assets/js/studio.js?v=' . OCS_VERSION,
				'types'     => $this->types(),
				'targets'   => $this->targets(),
				'positions' => $this->positions(),
				'i18n'      => $this->strings(),
			)
		);

		wp_enqueue_script( self::HANDLE );

		// Both this and the studio it imports are ES modules.
		add_filter( 'script_loader_tag', array( $this, 'as_module' ), 10, 3 );

		// The editor arrives as a dynamic import rather than a tag, so its
		// own configuration has to be on the page before it is asked for.
		( new Studio() )->print_config( self::HANDLE );
	}

	/**
	 * Turn our script tag into a module.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source.
	 * @return string
	 */
	public function as_module( $tag, $handle, $src ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		return '<script type="module" src="' . esc_url( $src ) . '" id="' . esc_attr( $handle ) . '-js"></script>' . "\n";
	}

	/**
	 * The three kinds of gallery, ready for the picker.
	 *
	 * @return array
	 */
	protected function types() {
		$out = array();

		foreach ( Positions::types() as $id => $spec ) {
			$out[] = array(
				'id'       => $id,
				'label'    => $spec['label'],
				'note'     => $spec['note'],
				'surfaces' => $spec['surfaces'],
			);
		}

		return $out;
	}

	/**
	 * Which pages a gallery can be sent to.
	 *
	 * @return array
	 */
	protected function targets() {
		$out = array();

		foreach ( Positions::targets() as $id => $spec ) {
			$out[] = array(
				'id'    => $id,
				'label' => $spec['label'],
			);
		}

		return $out;
	}

	/**
	 * Every branch of the matrix, so the picker never waits on the network.
	 *
	 * @return array
	 */
	protected function positions() {
		$out = array();

		foreach ( array_keys( Positions::types() ) as $type ) {
			$out[ $type ] = array();

			foreach ( array_keys( Positions::targets() ) as $target ) {
				$spots = array();

				foreach ( Positions::offered( $type, $target ) as $key => $spot ) {
					$spots[] = array(
						'id'    => $key,
						'label' => $spot['label'],
						'theme' => ! empty( $spot['theme'] ),
					);
				}

				$out[ $type ][ $target ] = $spots;
			}
		}

		return $out;
	}

	/**
	 * Screen text.
	 *
	 * @return array
	 */
	protected function strings() {
		return array(
			'title'            => __( 'Galleries', 'oc-story' ),
			'newGallery'       => __( 'New gallery', 'oc-story' ),
			'allGalleries'     => __( '← All galleries', 'oc-story' ),
			'empty'            => __( 'No galleries yet', 'oc-story' ),
			'emptyHint'        => __( 'A gallery is a set of videos and the place they appear. Making one takes three answers.', 'oc-story' ),

			'step1'            => __( 'What it is', 'oc-story' ),
			'step2'            => __( 'Where it goes', 'oc-story' ),
			'step3'            => __( 'The videos', 'oc-story' ),

			'name'             => __( 'Gallery name', 'oc-story' ),
			'nameNote'         => __( 'For you, in this screen. Shoppers never see it.', 'oc-story' ),
			'namePlaceholder'  => __( 'Influencers on the home page', 'oc-story' ),
			'whichType'        => __( 'What kind of gallery is this?', 'oc-story' ),
			'whichPages'       => __( 'Which pages should it appear on?', 'oc-story' ),
			'wherePage'        => __( 'Where on the page?', 'oc-story' ),
			'themeWarning'     => __( 'Depends on your theme', 'oc-story' ),

			'whichProduct'     => __( 'On which products?', 'oc-story' ),
			'automatic'        => __( 'Automatically', 'oc-story' ),
			'automaticNote'    => __( 'It appears only on the product pages its videos tag, and each product page shows only the videos that tag it.', 'oc-story' ),
			'namedProducts'    => __( 'On products I choose', 'oc-story' ),
			'namedProductsNote' => __( 'The same videos on every product you name.', 'oc-story' ),
			'whichCategory'    => __( 'In which categories?', 'oc-story' ),
			'whichPage'        => __( 'On which page?', 'oc-story' ),
			'search'           => __( 'Start typing a name…', 'oc-story' ),
			'remove'           => __( 'Remove', 'oc-story' ),

			'embedTitle'       => __( 'Placing it yourself', 'oc-story' ),
			'embedHow'         => __( 'Paste this anywhere a shortcode works — a page, a text widget, a builder block.', 'oc-story' ),
			'embedBuilder'     => __( 'Elementor and the block editor also have an OC Story block, if you would rather drag it into place.', 'oc-story' ),
			'afterSaving'      => __( 'The shortcode appears here once the gallery is saved.', 'oc-story' ),

			'theStories'       => __( 'The stories in this gallery', 'oc-story' ),
			'theVideos'        => __( 'The videos in this gallery', 'oc-story' ),
			'addStory'         => __( 'Add a story', 'oc-story' ),
			'addVideo'         => __( 'Add a video', 'oc-story' ),
			'floatingNote'     => __( 'A floating gallery shows one video in the corner. Tapping it opens the rest.', 'oc-story' ),
			'untitled'         => __( 'Untitled', 'oc-story' ),

			'back'             => __( 'Back', 'oc-story' ),
			'next'             => __( 'Next', 'oc-story' ),
			'publish'          => __( 'Save and publish', 'oc-story' ),
			'saveDraft'        => __( 'Save as a draft', 'oc-story' ),
			'published'        => __( 'Published. It is on the shop now.', 'oc-story' ),
			'publishing'       => __( 'Published. Checking the shop…', 'oc-story' ),
			'checkFound'       => __( 'Published, and it is showing where you put it.', 'oc-story' ),
			'checkMissing'     => __( 'Published, but it did not appear where you put it.', 'oc-story' ),
			'checkUnknown'     => __( 'Published. The shop could not be reached to confirm where it landed.', 'oc-story' ),
			'viewOnShop'       => __( 'See it on the shop', 'oc-story' ),
			'viewGallery'      => __( 'See the gallery on the shop', 'oc-story' ),
			'backToGalleries'  => __( 'Back to galleries', 'oc-story' ),
			'uploadNew'        => __( 'Upload a video', 'oc-story' ),
			'uploadNewNote'    => __( 'From this device. It is made smaller here in the browser before it is sent.', 'oc-story' ),
			'pickExisting'     => __( 'Choose one you already have', 'oc-story' ),
			/* translators: %d: number of videos. */
			'pickExistingNote' => __( '%d in your library, with the products already tagged on them.', 'oc-story' ),
			'pickExistingNone' => __( 'Nothing in your library yet.', 'oc-story' ),
			/* translators: %d: number of products. */
			'taggedProducts'   => __( '%d products tagged', 'oc-story' ),
			'noProducts'       => __( 'No products tagged', 'oc-story' ),
			'editVideo'        => __( 'Edit this video', 'oc-story' ),
			'cancel'           => __( 'Cancel', 'oc-story' ),
			'skipCheckout'     => __( 'Not on the cart, checkout or thank-you page', 'oc-story' ),
			'skipCheckoutNote' => __( 'A shopper on those pages is paying. Anything that pulls them away costs the sale.', 'oc-story' ),
			'draftSaved'       => __( 'Saved as a draft — nobody sees it yet.', 'oc-story' ),

			'type'             => __( 'Kind', 'oc-story' ),
			'where'            => __( 'Where', 'oc-story' ),
			'videos'           => __( 'Videos', 'oc-story' ),
			'status'           => __( 'Status', 'oc-story' ),
			'live'             => __( 'Live', 'oc-story' ),
			'duplicate'        => __( 'Duplicate', 'oc-story' ),
			'turnedOn'         => __( 'Switched on. Checking the shop…', 'oc-story' ),
			'turnedOff'        => __( 'Switched off. Nobody sees it now.', 'oc-story' ),
			'copySuffix'       => __( ' (copy)', 'oc-story' ),
			/* translators: %s: gallery name. */
			'removeSure'       => __( 'Remove the gallery “%s”? Its videos are kept.', 'oc-story' ),
			'removed'          => __( 'Gallery removed. Its videos are still in your library.', 'oc-story' ),
			'typeLocked'       => __( 'The kind of gallery is set when it is made. To change it, duplicate this one and choose again.', 'oc-story' ),
			'rowOrWall'        => __( 'A row or a wall?', 'oc-story' ),
			'asSlider'         => __( 'A row that scrolls', 'oc-story' ),
			'asGrid'           => __( 'A wall that wraps', 'oc-story' ),
			'draft'            => __( 'Draft', 'oc-story' ),
		);
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		echo '<div class="wrap ocs-wrap"><div id="ocs-wizard" class="ocs-app" data-loading="1">';
		echo '<div class="ocs-boot">' . esc_html__( 'Loading…', 'oc-story' ) . '</div>';
		echo '</div></div>';
	}
}
