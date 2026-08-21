<?php
/**
 * The page a share link opens on a phone.
 *
 * @package OC_Story
 */

namespace OCS\Display;

use OCS\Model\ShareLink;

defined( 'ABSPATH' ) || exit;

/**
 * A page of its own, not the theme's and not wp-admin's.
 *
 * The theme is skipped on purpose: this screen is one job on a phone held in
 * one hand, and a shop's header, menu, cookie bar and chat bubble are all
 * things to scroll past before reaching it. It is also the only page of the
 * shop that must never be indexed or leave its address in a referrer, which
 * is easier to be sure of when nothing else is on it.
 */
class UploadPage {

	const QUERY = 'ocs_upload';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	/**
	 * Let WordPress carry the token.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_var( $vars ) {
		$vars[] = self::QUERY;

		return $vars;
	}

	/**
	 * Render the page instead of whatever else was going to happen.
	 */
	public function maybe_render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET[ self::QUERY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) : '';

		if ( '' === $token ) {
			return;
		}

		// The token is not checked here. This page shows a screen and the
		// screen asks; every answer it can get comes from the REST door,
		// which checks on every request. A page that decided for itself would
		// be a second opinion to keep in step with the first.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'Referrer-Policy: no-referrer', true );

		$this->print_page( $token );
		exit;
	}

	/**
	 * The whole document.
	 *
	 * @param string $token Plaintext token, as given.
	 */
	protected function print_page( $token ) {
		$dir  = is_rtl() ? 'rtl' : 'ltr';
		$lang = get_bloginfo( 'language' );

		?><!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( sprintf( /* translators: %s: shop name. */ __( 'Add a video — %s', 'oc-story' ), get_bloginfo( 'name' ) ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( OCS_URL . 'assets/css/share.css?v=' . OCS_VERSION ); ?>">
</head>
<body class="ocs-share">
<div id="ocs-share" data-loading="1"></div>
<script>window.ocsShare = <?php echo wp_json_encode( $this->config( $token ) ); ?>;</script>
<script type="module" src="<?php echo esc_url( OCS_URL . 'assets/js/share.js?v=' . OCS_VERSION ); ?>"></script>
</body>
</html>
		<?php
	}

	/**
	 * What the screen is given to start with.
	 *
	 * @param string $token Plaintext token.
	 * @return array
	 */
	protected function config( $token ) {
		return array(
			'api'   => esc_url_raw( rest_url( 'oc-story/v1' ) ),
			'token' => $token,
			'shop'  => get_bloginfo( 'name' ),
			'days'  => ShareLink::SPANS,
			'i18n'  => array(
				'title'        => __( 'Add a video', 'oc-story' ),
				'checking'     => __( 'Checking the link…', 'oc-story' ),
				'claiming'     => __( 'Setting this phone up…', 'oc-story' ),
				'claimed'      => __( 'This phone is now the one this link works on.', 'oc-story' ),
				'pick'         => __( 'Choose a video', 'oc-story' ),
				'pickAgain'    => __( 'Choose a different video', 'oc-story' ),
				'shrinking'    => __( 'Making it smaller…', 'oc-story' ),
				'sending'      => __( 'Sending…', 'oc-story' ),
				'caption'      => __( 'A name for it', 'oc-story' ),
				'captionNote'  => __( 'For you, in the admin. Shoppers do not see it.', 'oc-story' ),
				'products'     => __( 'Which products are in it?', 'oc-story' ),
				'search'       => __( 'Start typing a product name…', 'oc-story' ),
				'remove'       => __( 'Remove', 'oc-story' ),
				'newStory'     => __( 'A new story', 'oc-story' ),
				'whichStory'   => __( 'Add it to…', 'oc-story' ),
				/* translators: %d: number of videos. */
				'storySlides'  => __( '%d so far', 'oc-story' ),
				'send'         => __( 'Add to the gallery', 'oc-story' ),
				'done'         => __( 'Added.', 'oc-story' ),
				'doneLive'     => __( 'It is on the shop now.', 'oc-story' ),
				'doneHeld'     => __( 'It is waiting for you in the admin.', 'oc-story' ),
				'doneNowhere'  => __( 'It is in the gallery — but this gallery only shows a video on the products that video names, and this one names none yet. Add a product to it in the admin and it appears there.', 'oc-story' ),
				'seeIt'        => __( 'See it on the shop', 'oc-story' ),
				'needProducts' => __( 'This gallery shows each video on the products it names, so choose at least one.', 'oc-story' ),
				'another'      => __( 'Add another', 'oc-story' ),
				'tooBig'       => __( 'That video is too large for this shop to accept.', 'oc-story' ),
				'tooLong'      => __( 'That video is longer than this shop allows.', 'oc-story' ),
				'failed'       => __( 'That did not work. Try again.', 'oc-story' ),
			),
		);
	}
}
