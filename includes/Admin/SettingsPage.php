<?php
/**
 * The settings screen.
 *
 * @package OC_Story
 */

namespace OCS\Admin;

use OCS\Core\Settings;
use OCS\Model\Story;

defined( 'ABSPATH' ) || exit;

/**
 * A classic options form, on purpose.
 *
 * The studio and the placements screen are applications because they are used
 * weekly and from a phone. Settings are visited twice in the life of an
 * install, and for that a plain WordPress form is faster to use, impossible to
 * break with JavaScript off, and native to the admin it sits in.
 *
 * Only settings a shop owner can act on are here. Encoder internals like
 * bitrate and keyframe interval stay as filters — a wrong value there produces
 * videos that stutter, and no label short enough for a form explains that.
 */
class SettingsPage {

	const SLUG = 'oc-story-settings';

	/**
	 * Handle a save, before any output.
	 */
	public function on_load() {
		if ( empty( $_POST['ocs_settings_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'ocs_save_settings', 'ocs_settings_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$post = wp_unslash( $_POST );

		$style = isset( $post['ring_style'] ) ? (string) $post['ring_style'] : 'gradient';
		if ( ! in_array( $style, array( 'gradient', 'solid', 'none' ), true ) ) {
			$style = 'gradient';
		}

		$nav = isset( $post['gallery_nav'] ) ? (string) $post['gallery_nav'] : 'arrows';
		if ( ! in_array( $nav, array( 'arrows', 'thumbs', 'none' ), true ) ) {
			$nav = 'arrows';
		}

		$cta = isset( $post['cta_style'] ) ? (string) $post['cta_style'] : 'button';
		if ( ! in_array( $cta, array( 'button', 'plus' ), true ) ) {
			$cta = 'button';
		}

		$panel = isset( $post['cart_panel'] ) ? (string) $post['cart_panel'] : 'compact';
		if ( ! in_array( $panel, array( 'compact', 'full' ), true ) ) {
			$panel = 'compact';
		}

		Settings::update(
			array(
				'gallery_nav'              => $nav,
				'cta_style'                => $cta,
				'cart_panel'               => $panel,
				'cta_label'                => mb_substr( trim( wp_strip_all_tags( (string) ( $post['cta_label'] ?? '' ) ) ), 0, 24 ),
				'cta_color'                => sanitize_hex_color( (string) ( $post['cta_color'] ?? '' ) ),
				'backdrop'                 => empty( $post['backdrop'] ) ? 'solid' : 'dim',
				// Look.
				'ring_style'               => $style,
				'ring_color'               => sanitize_hex_color( (string) ( $post['ring_color'] ?? '' ) ),
				'ring_seen_color'          => sanitize_hex_color( (string) ( $post['ring_seen_color'] ?? '' ) ),

				// Player.
				'advance_to_next_story'    => empty( $post['advance_to_next_story'] ) ? 'no' : 'yes',
				'show_product_strip'       => empty( $post['show_product_strip'] ) ? 'no' : 'yes',
				'card_autoplay'            => empty( $post['card_autoplay'] ) ? 'no' : 'yes',

				// Video.
				'max_long_side'            => max( 480, min( 1920, (int) ( $post['max_long_side'] ?? 1280 ) ) ),
				'max_slide_seconds'        => max( 15, min( 180, (int) ( $post['max_slide_seconds'] ?? 60 ) ) ),
				'max_upload_mb'            => max( 20, min( 1024, (int) ( $post['max_upload_mb'] ?? 200 ) ) ),

				// Analytics.
				'analytics_enabled'        => empty( $post['analytics_enabled'] ) ? 'no' : 'yes',
				'attribution_days'         => max( 1, min( 30, (int) ( $post['attribution_days'] ?? 7 ) ) ),

				// Housekeeping.
				'delete_data_on_uninstall' => empty( $post['delete_data_on_uninstall'] ) ? 'no' : 'yes',
			)
		);

		// Ring colours are inlined into cached bars.
		Story::bump_version();
		\OCS\Core\CacheFlush::pages();

		wp_safe_redirect( add_query_arg( 'updated', '1', menu_page_url( self::SLUG, false ) ) );
		exit;
	}

	/**
	 * Render the form.
	 */
	public function render() {
		$s = Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OC Story settings', 'oc-story' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'oc-story' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'ocs_save_settings', 'ocs_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Look', 'oc-story' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ocs-ring-style"><?php esc_html_e( 'Circle ring', 'oc-story' ); ?></label></th>
						<td>
							<select name="ring_style" id="ocs-ring-style">
								<option value="gradient" <?php selected( $s['ring_style'], 'gradient' ); ?>><?php esc_html_e( 'Story gradient', 'oc-story' ); ?></option>
								<option value="solid" <?php selected( $s['ring_style'], 'solid' ); ?>><?php esc_html_e( 'One colour', 'oc-story' ); ?></option>
								<option value="none" <?php selected( $s['ring_style'], 'none' ); ?>><?php esc_html_e( 'No ring', 'oc-story' ); ?></option>
							</select>
							<input type="color" name="ring_color" value="<?php echo esc_attr( $s['ring_color'] ? $s['ring_color'] : '#d6249f' ); ?>" aria-label="<?php esc_attr_e( 'Ring colour', 'oc-story' ); ?>">
							<p class="description"><?php esc_html_e( 'The colour applies when the ring is one colour.', 'oc-story' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ocs-seen"><?php esc_html_e( 'Ring after watching', 'oc-story' ); ?></label></th>
						<td><input type="color" id="ocs-seen" name="ring_seen_color" value="<?php echo esc_attr( $s['ring_seen_color'] ? $s['ring_seen_color'] : '#c7c7c7' ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Player', 'oc-story' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'When a story ends', 'oc-story' ); ?></th>
						<td>
							<label><input type="checkbox" name="advance_to_next_story" <?php checked( 'yes' === $s['advance_to_next_story'] ); ?>> <?php esc_html_e( 'Continue to the next story', 'oc-story' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Products', 'oc-story' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_product_strip" <?php checked( 'yes' === $s['show_product_strip'] ); ?>> <?php esc_html_e( 'Show tagged products over the video', 'oc-story' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ocs-nav"><?php esc_html_e( 'Moving between galleries', 'oc-story' ); ?></label></th>
						<td>
							<select name="gallery_nav" id="ocs-nav">
								<option value="arrows" <?php selected( $s['gallery_nav'], 'arrows' ); ?>><?php esc_html_e( 'Arrows beside the video', 'oc-story' ); ?></option>
								<option value="thumbs" <?php selected( $s['gallery_nav'], 'thumbs' ); ?>><?php esc_html_e( 'Thumbnails of the other galleries', 'oc-story' ); ?></option>
								<option value="none" <?php selected( $s['gallery_nav'], 'none' ); ?>><?php esc_html_e( 'Nothing — swipe and keys only', 'oc-story' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Phones always swipe; this is what a desktop shows.', 'oc-story' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ocs-panel"><?php esc_html_e( 'Tapping a product opens', 'oc-story' ); ?></label></th>
						<td>
							<select name="cart_panel" id="ocs-panel">
								<option value="compact" <?php selected( $s['cart_panel'], 'compact' ); ?>><?php esc_html_e( 'Just the choices and a button', 'oc-story' ); ?></option>
								<option value="full" <?php selected( $s['cart_panel'], 'full' ); ?>><?php esc_html_e( 'The product itself — photos, price, description, quantity', 'oc-story' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'The narrow one keeps the video in view. The wide one saves a trip to the product page.', 'oc-story' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ocs-cta"><?php esc_html_e( 'The button on a product', 'oc-story' ); ?></label></th>
						<td>
							<select name="cta_style" id="ocs-cta">
								<option value="button" <?php selected( $s['cta_style'], 'button' ); ?>><?php esc_html_e( 'A button with words', 'oc-story' ); ?></option>
								<option value="plus" <?php selected( $s['cta_style'], 'plus' ); ?>><?php esc_html_e( 'A round plus', 'oc-story' ); ?></option>
							</select>
							<p>
								<input type="text" name="cta_label" value="<?php echo esc_attr( $s['cta_label'] ); ?>" class="regular-text" maxlength="24" placeholder="<?php esc_attr_e( 'Buy', 'oc-story' ); ?>">
								<span class="description"><?php esc_html_e( 'What it says. A plus still says this to a screen reader.', 'oc-story' ); ?></span>
							</p>
							<p>
								<input type="text" name="cta_color" value="<?php echo esc_attr( $s['cta_color'] ); ?>" class="ocs-color" placeholder="#111111">
								<span class="description"><?php esc_html_e( 'Its colour. Empty means near-black.', 'oc-story' ); ?></span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Behind the video', 'oc-story' ); ?></th>
						<td>
							<label><input type="checkbox" name="backdrop" <?php checked( 'dim' === $s['backdrop'] ); ?>> <?php esc_html_e( 'Let the page show through, dimmed', 'oc-story' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Video cards', 'oc-story' ); ?></th>
						<td>
							<label><input type="checkbox" name="card_autoplay" <?php checked( 'yes' === $s['card_autoplay'] ); ?>> <?php esc_html_e( 'Play a silent preview in the cards, one at a time', 'oc-story' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only one video plays anywhere on the page, and only while the row is on screen.', 'oc-story' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Video', 'oc-story' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ocs-side"><?php esc_html_e( 'Quality', 'oc-story' ); ?></label></th>
						<td>
							<select name="max_long_side" id="ocs-side">
								<option value="960" <?php selected( (int) $s['max_long_side'], 960 ); ?>><?php esc_html_e( 'Compact — smallest files', 'oc-story' ); ?></option>
								<option value="1280" <?php selected( (int) $s['max_long_side'], 1280 ); ?>><?php esc_html_e( 'Balanced (recommended)', 'oc-story' ); ?></option>
								<option value="1920" <?php selected( (int) $s['max_long_side'], 1920 ); ?>><?php esc_html_e( 'Sharp — larger files', 'oc-story' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Videos are compressed on your device before upload.', 'oc-story' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ocs-seconds"><?php esc_html_e( 'Longest slide, in seconds', 'oc-story' ); ?></label></th>
						<td><input type="number" id="ocs-seconds" name="max_slide_seconds" min="15" max="180" value="<?php echo esc_attr( (int) $s['max_slide_seconds'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ocs-mb"><?php esc_html_e( 'Largest upload, in megabytes', 'oc-story' ); ?></label></th>
						<td><input type="number" id="ocs-mb" name="max_upload_mb" min="20" max="1024" value="<?php echo esc_attr( (int) $s['max_upload_mb'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Measurement', 'oc-story' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Analytics', 'oc-story' ); ?></th>
						<td>
							<label><input type="checkbox" name="analytics_enabled" <?php checked( 'yes' === $s['analytics_enabled'] ); ?>> <?php esc_html_e( 'Count views, taps and revenue', 'oc-story' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ocs-attr"><?php esc_html_e( 'Credit a sale to a story for, in days', 'oc-story' ); ?></label></th>
						<td><input type="number" id="ocs-attr" name="attribution_days" min="1" max="30" value="<?php echo esc_attr( (int) $s['attribution_days'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'If the plugin is ever removed', 'oc-story' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Data', 'oc-story' ); ?></th>
						<td>
							<label><input type="checkbox" name="delete_data_on_uninstall" <?php checked( 'yes' === $s['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete stories and settings on uninstall', 'oc-story' ); ?></label>
							<p class="description"><?php esc_html_e( 'Videos and posters stay in the media library either way.', 'oc-story' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
