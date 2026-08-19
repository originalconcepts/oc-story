<?php
/**
 * The circles bar.
 *
 * Override by copying to `oc-story/surfaces/circles.php` in the theme.
 *
 * @var array  $stories   Stories.
 * @var array  $placement Placement.
 * @var string $inline    Inline JSON payload, or ''.
 * @var string $src       REST URL when the payload was not inlined.
 * @var string $style     Per-device custom properties.
 *
 * @package OC_Story
 */

defined( 'ABSPATH' ) || exit;

$ocs_labels_desktop = $placement['desktop']['labels'] ? '1' : '0';
$ocs_labels_mobile  = $placement['mobile']['labels'] ? '1' : '0';
$ocs_max            = max( (int) $placement['desktop']['max'], (int) $placement['mobile']['max'] );
?>
<div
	class="ocs-bar"
	data-ocs-bar="<?php echo esc_attr( $placement['id'] ); ?>"
	data-ocs-labels="<?php echo esc_attr( $ocs_labels_desktop . $ocs_labels_mobile ); ?>"
	<?php if ( '' !== $src ) : ?>
		data-ocs-src="<?php echo esc_url( $src ); ?>"
	<?php endif; ?>
	style="<?php echo esc_attr( $style ); ?>"
>
	<div class="ocs-bar__track">
		<?php
		$ocs_shown = 0;
		foreach ( $stories as $ocs_story ) :
			if ( ++$ocs_shown > $ocs_max ) {
				break;
			}

			$ocs_poster = $ocs_story['poster_url'];
			$ocs_title  = $ocs_story['title'];
			?>
			<button
				type="button"
				class="ocs-circle"
				data-ocs-open="<?php echo esc_attr( $ocs_story['id'] ); ?>"
				aria-label="<?php echo esc_attr( $ocs_title ? $ocs_title : __( 'Watch story', 'oc-story' ) ); ?>"
			>
				<span class="ocs-circle__ring">
					<?php if ( $ocs_poster ) : ?>
						<?php
						// width and height are explicit and the aspect ratio is
						// fixed in CSS, so the bar reserves its space before the
						// first poster arrives. This is the whole of our CLS
						// budget: zero.
						?>
						<img
							class="ocs-circle__img skip-lazy"
							src="<?php echo esc_url( $ocs_poster ); ?>"
							alt=""
							width="160"
							height="160"
							decoding="async"
							fetchpriority="low"
							loading="lazy"
							data-no-lazy="1"
							data-skip-lazy
						/>
					<?php else : ?>
						<span class="ocs-circle__img ocs-circle__img--empty"></span>
					<?php endif; ?>
				</span>
				<?php if ( '' !== $ocs_title ) : ?>
					<span class="ocs-circle__label"><?php echo esc_html( $ocs_title ); ?></span>
				<?php endif; ?>
			</button>
		<?php endforeach; ?>
	</div>
</div>
<?php
// Not escaped: this is JSON we encoded ourselves, inside a JSON script type.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $inline;
