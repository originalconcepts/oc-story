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
			$ocs_poster   = $ocs_story['poster_url'];
			$ocs_title    = $ocs_story['title'];
			$ocs_products = 0;
			$ocs_seconds  = 0;

			foreach ( $ocs_story['slides'] as $ocs_slide ) {
				$ocs_products += count( $ocs_slide['products'] );
				$ocs_seconds  += (float) $ocs_slide['duration'];
			}
			?>
			<button
				type="button"
				class="ocs-card"
				data-ocs-open="<?php echo esc_attr( $ocs_story['id'] ); ?>"
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

					<?php if ( $ocs_seconds >= 1 ) : ?>
						<span class="ocs-card__time"><?php echo esc_html( gmdate( 'i:s', (int) round( $ocs_seconds ) ) ); ?></span>
					<?php endif; ?>

					<?php if ( $ocs_products > 0 ) : ?>
						<span class="ocs-card__tag">
							<?php
							printf(
								/* translators: %d: number of products tagged in the video */
								esc_html( _n( '%d product', '%d products', $ocs_products, 'oc-story' ) ),
								(int) $ocs_products
							);
							?>
						</span>
					<?php endif; ?>
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
