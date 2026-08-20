<?php
/**
 * The video slider, shared by the slider and product-page surfaces.
 *
 * Override by copying to `oc-story/surfaces/slider.php` in the theme.
 *
 * @var array  $stories   Stories.
 * @var array  $placement Placement.
 * @var string $inline    Inline JSON payload, or ''.
 * @var string $src       REST URL when the payload was not inlined.
 * @var string $style     Per-device custom properties.
 * @var string $heading   Optional heading above the row.
 * @var bool   $autoplay  Whether cards preview themselves silently.
 *
 * @package OC_Story
 */

defined( 'ABSPATH' ) || exit;

$ocs_max    = max( (int) $placement['desktop']['max'], (int) $placement['mobile']['max'] );
$ocs_labels = ( $placement['desktop']['labels'] ? '1' : '0' ) . ( $placement['mobile']['labels'] ? '1' : '0' );
?>
<div
	class="ocs-slider ocs-surface--<?php echo esc_attr( $placement['surface'] ); ?>"
	data-ocs-bar="<?php echo esc_attr( $placement['id'] ); ?>"
	data-ocs-surface="<?php echo esc_attr( $placement['surface'] ); ?>"
	data-ocs-labels="<?php echo esc_attr( $ocs_labels ); ?>"
	<?php if ( ! empty( $autoplay ) ) : ?>
		data-ocs-autoplay="1"
	<?php endif; ?>
	<?php if ( '' !== $src ) : ?>
		data-ocs-src="<?php echo esc_url( $src ); ?>"
	<?php endif; ?>
	style="<?php echo esc_attr( $style ); ?>"
>
	<?php if ( '' !== $heading ) : ?>
		<h2 class="ocs-slider__heading"><?php echo esc_html( $heading ); ?></h2>
	<?php endif; ?>

	<div class="ocs-slider__track">
		<?php
		$ocs_shown = 0;
		foreach ( $stories as $ocs_story ) :
			if ( ++$ocs_shown > $ocs_max ) {
				break;
			}

			$ocs_first    = isset( $ocs_story['slides'][0] ) ? $ocs_story['slides'][0] : null;

			// The clip the silent preview plays. Only video slides qualify —
			// and it is only an attribute here; nothing loads until the card's
			// turn comes round, in view.
			$ocs_preview = ( $ocs_first && 'image' !== $ocs_first['type'] && '' !== $ocs_first['url'] )
				? $ocs_first['url']
				: '';
			$ocs_poster   = $ocs_story['poster_url'];
			$ocs_title    = $ocs_story['title'];
			?>
			<button
				type="button"
				class="ocs-card"
				data-ocs-open="<?php echo esc_attr( $ocs_story['id'] ); ?>"
				<?php if ( '' !== $ocs_preview ) : ?>
					data-ocs-preview="<?php echo esc_url( $ocs_preview ); ?>"
				<?php endif; ?>
				aria-label="<?php echo esc_attr( $ocs_title ? $ocs_title : __( 'Watch video', 'oc-story' ) ); ?>"
			>
				<span class="ocs-card__frame">
					<?php if ( $ocs_poster ) : ?>
						<?php
						// The intrinsic size is the 9:16 the studio encodes to.
						// Explicit dimensions plus a fixed aspect ratio in CSS
						// is the whole of the CLS budget: zero.
						?>
						<img
							class="ocs-card__img skip-lazy"
							src="<?php echo esc_url( $ocs_poster ); ?>"
							alt=""
							width="270"
							height="480"
							decoding="async"
							fetchpriority="low"
							loading="lazy"
							data-no-lazy="1"
							data-skip-lazy
						/>
					<?php else : ?>
						<span class="ocs-card__img"></span>
					<?php endif; ?>

					<span class="ocs-card__play" aria-hidden="true"></span>


				</span>

				<?php if ( '' !== $ocs_title ) : ?>
					<span class="ocs-card__label"><?php echo esc_html( $ocs_title ); ?></span>
				<?php endif; ?>
			</button>
		<?php endforeach; ?>
	</div>
</div>
<?php
// Not escaped: JSON we encoded ourselves, inside a JSON script type.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $inline;
