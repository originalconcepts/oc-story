<?php
/**
 * The studio screen.
 *
 * @package OC_Story
 */

namespace OCS\Admin;

use OCS\Core\Settings;
use OCS\Media\Probe;

defined( 'ABSPATH' ) || exit;

/**
 * One screen, the same code on a desktop and on a phone.
 *
 * The shop owner records on their phone and will publish from it, so this is not
 * a desktop screen with a mobile fallback — it is one layout that reflows. The
 * WordPress admin chrome is collapsed away on a narrow viewport so the studio
 * gets the whole display.
 */
class Studio {

	const HANDLE = 'ocs-studio';

	/**
	 * Runs only when our screen is being loaded.
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
	 * Register and enqueue the studio assets.
	 */
	public function enqueue() {
		wp_enqueue_style( self::HANDLE, OCS_URL . 'assets/css/studio.css', array(), OCS_VERSION );

		wp_register_script( self::HANDLE, OCS_URL . 'assets/js/studio.js', array(), OCS_VERSION, true );

		wp_localize_script(
			self::HANDLE,
			'ocsStudio',
			array(
				'api'      => array(
					'root'  => esc_url_raw( rest_url( 'oc-story/v1' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				),
				'encode'   => array(
					'enabled' => Settings::is( 'encode_enabled' ),
					'maxSide' => (int) Settings::get( 'max_long_side', 1280 ),
					'bitrate' => (int) Settings::get( 'target_bitrate', 1500000 ),
					'fps'     => (int) Settings::get( 'target_fps', 30 ),
				),
				'limits'   => array(
					'maxSeconds' => (int) Settings::get( 'max_slide_seconds', 60 ),
					'maxBytes'   => max( 1, (int) Settings::get( 'max_upload_mb', 200 ) ) * MB_IN_BYTES,
					'hasFfmpeg'  => Probe::has_ffmpeg(),
				),
				'labels'   => array(
					'untitled' => __( 'Untitled story', 'oc-story' ),
				),
				'i18n'     => $this->strings(),
			)
		);

		wp_enqueue_script( self::HANDLE );

		// The studio is an ES module: it imports the encoder, and the encoder
		// imports the MP4 code. WordPress has no first-class way to say so.
		add_filter( 'script_loader_tag', array( $this, 'as_module' ), 10, 3 );
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
	 * Everything the interface says, translated once.
	 *
	 * @return array<string,string>
	 */
	protected function strings() {
		return array(
			'studio'         => __( 'Studio', 'oc-story' ),
			'newStory'       => __( 'New story', 'oc-story' ),
			'addSlide'       => __( 'Add video', 'oc-story' ),
			'empty'          => __( 'No stories yet', 'oc-story' ),
			'emptyHint'      => __( 'Add a video, tag the products in it, and publish. That is the whole thing.', 'oc-story' ),
			'title'          => __( 'Caption under the circle', 'oc-story' ),
			'products'       => __( 'Products in this video', 'oc-story' ),
			'searchProducts' => __( 'Type a product name…', 'oc-story' ),
			'noProducts'     => __( 'Nothing found', 'oc-story' ),
			'noneTagged'     => __( 'No products tagged yet.', 'oc-story' ),
			'pin'            => __( 'Place on the frame', 'oc-story' ),
			'unpin'          => __( 'Remove from the frame', 'oc-story' ),
			'pinHint'        => __( 'Drag a pin to where the product appears.', 'oc-story' ),
			'slides'         => __( 'Videos', 'oc-story' ),
			'views7d'        => __( 'views this week', 'oc-story' ),
			'publish'        => __( 'Publish', 'oc-story' ),
			'unpublish'      => __( 'Move to draft', 'oc-story' ),
			'published'      => __( 'Live', 'oc-story' ),
			'draft'          => __( 'Draft', 'oc-story' ),
			'save'           => __( 'Save', 'oc-story' ),
			'saving'         => __( 'Saving…', 'oc-story' ),
			'saved'          => __( 'Saved', 'oc-story' ),
			'close'          => __( 'Close', 'oc-story' ),
			'delete'         => __( 'Delete story', 'oc-story' ),
			'deleteSlide'    => __( 'Remove this video', 'oc-story' ),
			'confirmDelete'  => __( 'Delete this story? The video stays in your media library.', 'oc-story' ),
			'compressing'    => __( 'Compressing on your device…', 'oc-story' ),
			'uploading'      => __( 'Uploading…', 'oc-story' ),
			'shrank'         => __( '%1$s became %2$s', 'oc-story' ),
			'tooLong'        => __( 'Only the first %d seconds are used.', 'oc-story' ),
			'noEncoder'      => __( 'This browser cannot compress video, so the file is uploaded as it is. It may be slow.', 'oc-story' ),
			'noDecoder'      => __( 'This device cannot open that video format (%s). Try sharing it through WhatsApp first, or record in "Most Compatible".', 'oc-story' ),
			'tooLarge'       => __( 'That file is too large to upload here.', 'oc-story' ),
			'failed'         => __( 'That did not work', 'oc-story' ),
			'retry'          => __( 'Try again', 'oc-story' ),
			'dragHint'       => __( 'Drag to reorder', 'oc-story' ),
		);
	}

	/**
	 * Render the app root. Everything inside it is built by studio.js.
	 */
	public function render() {
		echo '<div class="wrap ocs-wrap"><div id="ocs-studio" class="ocs-app" data-loading="1">';
		echo '<div class="ocs-boot">' . esc_html__( 'Loading the studio…', 'oc-story' ) . '</div>';
		echo '</div></div>';
	}
}
