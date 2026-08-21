<?php
/**
 * The floating video.
 *
 * Override by copying to `oc-story/surfaces/floating.php` in the theme.
 *
 * @var array  $stories   Stories.
 * @var array  $story     The one being shown.
 * @var array  $slide     Its first slide.
 * @var array  $placement Placement.
 * @var string $inline    Inline JSON payload, or ''.
 * @var string $src       REST URL when the payload was not inlined.
 * @var string $style     Per-device custom properties.
 * @var string $side      'start' or 'end'.
 *
 * @package OC_Story
 */

defined( 'ABSPATH' ) || exit;

$ocs_poster = ! empty( $slide['poster_url'] ) ? $slide['poster_url'] : ( ! empty( $story['thumb_url'] ) ? $story['thumb_url'] : '' );
$ocs_video  = ! empty( $slide['url'] ) && 'image' !== ( isset( $slide['type'] ) ? $slide['type'] : 'video' ) ? $slide['url'] : '';
$ocs_title  = isset( $story['title'] ) ? (string) $story['title'] : '';
?>
<div
	class="ocs-float"
	data-ocs-bar="<?php echo esc_attr( $placement['id'] ); ?>"
	data-ocs-surface="floating"
	data-ocs-side="<?php echo esc_attr( $side ); ?>"
	<?php if ( '' !== $ocs_video ) : ?>
		data-ocs-float-src="<?php echo esc_url( $ocs_video ); ?>"
	<?php endif; ?>
	<?php if ( '' !== $src ) : ?>
		data-ocs-src="<?php echo esc_url( $src ); ?>"
	<?php endif; ?>
	style="<?php echo esc_attr( $style ); ?>"
	hidden
>
	<button
		type="button"
		class="ocs-float__open"
		data-ocs-open="<?php echo esc_attr( $story['id'] ); ?>"
		aria-label="<?php echo esc_attr( $ocs_title ? $ocs_title : __( 'Watch video', 'oc-story' ) ); ?>"
	>
		<?php if ( $ocs_poster ) : ?>
			<img
				class="ocs-float__poster skip-lazy"
				src="<?php echo esc_url( $ocs_poster ); ?>"
				alt=""
				width="180"
				height="320"
				decoding="async"
				fetchpriority="low"
				loading="lazy"
				data-no-lazy="1"
				data-skip-lazy
			/>
		<?php endif; ?>
		<span class="ocs-float__play" aria-hidden="true"></span>
	</button>
	<button
		type="button"
		class="ocs-float__close"
		data-ocs-float-close
		aria-label="<?php esc_attr_e( 'Hide this video', 'oc-story' ); ?>"
	>&times;</button>
</div>
<?php
// Not escaped: this is JSON we encoded ourselves, inside a JSON script type.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $inline;
