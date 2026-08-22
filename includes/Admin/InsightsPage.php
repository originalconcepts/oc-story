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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from_raw = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to_raw   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$days     = isset( $_GET['days'] ) ? max( 1, min( 3650, (int) $_GET['days'] ) ) : 30;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$dated = '' !== $from_raw || '' !== $to_raw;

		list( $from, $to ) = $dated ? Stats::span( $from_raw, $to_raw ) : Stats::span( $days );

		$rows  = Stats::by_story( $from, $to );
		$reach = Stats::reach( $from, $to );

		// Which galleries currently show each video. The numbers themselves
		// are per video — one video can be in several galleries, so an open
		// cannot honestly be credited to one of them — but "where is this
		// shown" is answerable, and it is the question the word "gallery" in
		// this screen used to answer wrongly.
		$shown_in = array();

		foreach ( \OCS\Model\Placement::all() as $placement ) {
			foreach ( (array) $placement['stories']['ids'] as $story_id ) {
				$shown_in[ (int) $story_id ][] = $placement['label'] ? $placement['label'] : $placement['id'];
			}
		}

		$totals = array(
			'opens'        => 0,
			'completions'  => 0,
			'product_taps' => 0,
			'sparks'       => 0,
			'likes'        => 0,
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
						esc_url( add_query_arg( array( 'days' => $option, 'from' => false, 'to' => false ) ) ),
						! $dated && $days === $option ? ' button-primary' : '',
						/* translators: %d: number of days */
						esc_html( sprintf( __( '%d days', 'oc-story' ), $option ) )
					);
				}
				?>
			</p>

			<form method="get" style="margin:0 0 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="ocs-from"><?php esc_html_e( 'From', 'oc-story' ); ?></label>
				<input type="date" id="ocs-from" name="from" value="<?php echo esc_attr( $from ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
				<label for="ocs-to"><?php esc_html_e( 'To', 'oc-story' ); ?></label>
				<input type="date" id="ocs-to" name="to" value="<?php echo esc_attr( $to ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Show', 'oc-story' ); ?></button>
				<span class="description">
					<?php
					printf(
						/* translators: 1: start date, 2: end date */
						esc_html__( 'Showing %1$s to %2$s', 'oc-story' ),
						esc_html( $from ),
						esc_html( $to )
					);
					?>
				</span>
			</form>

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
						<th><?php esc_html_e( 'Video', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Shown in', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Opens', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Watched to the end', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Likes', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Sparks', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Product taps', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Added to cart', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'oc-story' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'oc-story' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$exists = (bool) get_post_status( $row['story_id'] );
						$title  = $exists ? get_the_title( $row['story_id'] ) : '';
						$where  = isset( $shown_in[ $row['story_id'] ] ) ? $shown_in[ $row['story_id'] ] : array();
						$rate   = $row['opens'] > 0 ? round( ( $row['completions'] / $row['opens'] ) * 100 ) : 0;

						if ( '' === $title ) {
							$title = $exists
								? __( 'Untitled', 'oc-story' )
								/* translators: %d: video id */
								: sprintf( __( 'Deleted video #%d', 'oc-story' ), $row['story_id'] );
						}
						?>
						<tr>
							<td><strong><?php echo esc_html( $title ); ?></strong></td>
							<td>
								<?php
								// Numbers are never removed, so a video can be
								// here long after it left every gallery — or
								// after it was deleted. Saying which is the
								// difference between a stale row and a record.
								echo $where
									? esc_html( implode( ', ', $where ) )
									: '<span class="description">' . esc_html__( 'Not in any gallery now', 'oc-story' ) . '</span>';
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $row['opens'] ) ); ?></td>
							<td>
								<?php
								/* translators: %d: percentage of opens watched to the end */
								echo esc_html( sprintf( __( '%d%%', 'oc-story' ), $rate ) );
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $row['likes'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['sparks'] ) ); ?></td>
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
						<th></th>
						<th><?php echo esc_html( number_format_i18n( $totals['opens'] ) ); ?></th>
						<th>
							<?php
							$total_rate = $totals['opens'] > 0 ? round( ( $totals['completions'] / $totals['opens'] ) * 100 ) : 0;
							/* translators: %d: percentage of opens watched to the end */
							echo esc_html( sprintf( __( '%d%%', 'oc-story' ), $total_rate ) );
							?>
						</th>
						<th><?php echo esc_html( number_format_i18n( $totals['likes'] ) ); ?></th>
						<th><?php echo esc_html( number_format_i18n( $totals['sparks'] ) ); ?></th>
						<th><?php echo esc_html( number_format_i18n( $totals['product_taps'] ) ); ?></th>
						<th><?php echo esc_html( number_format_i18n( $totals['add_to_cart'] ) ); ?></th>
						<th><?php echo esc_html( number_format_i18n( $totals['orders'] ) ); ?></th>
						<th><strong><?php echo wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $totals['revenue'] ) : number_format_i18n( $totals['revenue'], 2 ) ); ?></strong></th>
					</tr>
				</tfoot>
			</table>

			<p class="description" style="margin-top:12px">
				<?php esc_html_e( 'Numbers are kept for ever. Switching a gallery off, taking a video out of it, or deleting the video does not remove what it already earned.', 'oc-story' ); ?>
			</p>

			<p class="description">
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
