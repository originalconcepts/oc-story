<?php
/**
 * The insights screen.
 *
 * @package OC_Story
 */

namespace OCS\Admin;

use OCS\Model\Stats;
use OCS\Model\Story;

defined( 'ABSPATH' ) || exit;

/**
 * One table that answers the only question that matters: which videos sell.
 *
 * Server-rendered on purpose, like the settings — it is read weekly, not
 * driven daily, and a plain table the shop owner can screenshot into a WhatsApp
 * message is worth more than a dashboard that needs a tour.
 */
class InsightsPage {

	const SLUG = 'oc-story-insights';

	/**
	 * Render.
	 */
	public function render() {
		$days = isset( $_GET['days'] ) ? max( 1, min( 90, (int) $_GET['days'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$rows  = Stats::by_story( $days );
		$reach = Stats::reach( $days );

		$totals = array(
			'opens'        => 0,
			'completions'  => 0,
			'product_taps' => 0,
			'add_to_cart'  => 0,
			'orders'       => 0,
			'revenue'      => 0.0,
		);

		foreach ( $rows as $row ) {
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] += $row[ $key ];
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OC Story insights', 'oc-story' ); ?></h1>

			<p>
				<?php
				foreach ( array( 7, 30, 90 ) as $option ) {
					printf(
						'<a href="%s" class="button%s" style="margin-inline-end:6px">%s</a>',
						esc_url( add_query_arg( 'days', $option ) ),
						$days === $option ? ' button-primary' : '',
						/* translators: %d: number of days */
						esc_html( sprintf( __( '%d days', 'oc-story' ), $option ) )
					);
				}
				?>
			</p>

			<?php if ( ! $rows ) : ?>
				<div class="notice notice-info inline"><p>
					<?php esc_html_e( 'Nothing measured yet. Numbers appear here once shoppers start watching.', 'oc-story' ); ?>
				</p></div>
				<?php
				echo '</div>';
				return;
			endif;
			?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: number of pageviews that displayed a story bar */
					esc_html__( 'Pages that showed stories in this period: %s', 'oc-story' ),
					esc_html( number_format_i18n( $reach ) )
				);
				?>
			</p>

			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Story', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Opens', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Watched to the end', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Product taps', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Added to cart', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'oc-story' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$title = get_the_title( $row['story_id'] );
						$rate  = $row['opens'] > 0 ? round( ( $row['completions'] / $row['opens'] ) * 100 ) : 0;
						?>
						<tr>
							<td><strong><?php echo esc_html( '' !== $title ? $title : sprintf( '#%d', $row['story_id'] ) ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $row['opens'] ) ); ?></td>
							<td>
								<?php
								/* translators: %d: percentage of opens watched to the end */
								echo esc_html( sprintf( __( '%d%%', 'oc-story' ), $rate ) );
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $row['product_taps'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['add_to_cart'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['orders'] ) ); ?></td>
							<td><strong><?php echo wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $row['revenue'] ) : number_format_i18n( $row['revenue'], 2 ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th><?php esc_html_e( 'Everything together', 'oc-story' ); ?></th>
						<th><?php echo esc_html( number_format_i18n( $totals['opens'] ) ); ?></th>
						<th>
							<?php
							$total_rate = $totals['opens'] > 0 ? round( ( $totals['completions'] / $totals['opens'] ) * 100 ) : 0;
							/* translators: %d: percentage of opens watched to the end */
							echo esc_html( sprintf( __( '%d%%', 'oc-story' ), $total_rate ) );
							?>
						</th>
						<th><?php echo esc_html( number_format_i18n( $totals['product_taps'] ) ); ?></th>
						<th><?php echo esc_html( number_format_i18n( $totals['add_to_cart'] ) ); ?></th>
						<th><?php echo esc_html( number_format_i18n( $totals['orders'] ) ); ?></th>
						<th><strong><?php echo wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $totals['revenue'] ) : number_format_i18n( $totals['revenue'], 2 ) ); ?></strong></th>
					</tr>
				</tfoot>
			</table>

			<p class="description" style="margin-top:12px">
				<?php
				printf(
					/* translators: %d: attribution window in days */
					esc_html__( 'A sale is credited to a story when the shopper tapped its product within the last %d days. Change the window under Settings.', 'oc-story' ),
					(int) \OCS\Core\Settings::get( 'attribution_days', 7 )
				);
				?>
			</p>
		</div>
		<?php
	}
}
