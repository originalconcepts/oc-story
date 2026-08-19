<?php
/**
 * Uninstall routine.
 *
 * A video library is expensive to produce and impossible to recover, so nothing
 * here runs unless the shop explicitly asked for it in the settings. The default
 * is to leave every story, video and poster exactly where it is.
 *
 * @package OC_Story
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ocs_settings = get_option( 'ocs_settings', array() );

if ( ! is_array( $ocs_settings ) || 'yes' !== ( $ocs_settings['delete_data_on_uninstall'] ?? 'no' ) ) {
	return;
}

global $wpdb;

// Custom tables.
foreach ( array( 'slide_product', 'stats_daily', 'uploads' ) as $ocs_table ) {
	$ocs_name = $wpdb->prefix . 'ocs_' . $ocs_table;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$ocs_name}" );
}

// Options.
foreach ( array( 'ocs_settings', 'ocs_db_version', 'ocs_placements' ) as $ocs_option ) {
	delete_option( $ocs_option );
}

// Story posts and their slide meta.
$ocs_stories = get_posts(
	array(
		'post_type'        => 'oc_story',
		'post_status'      => 'any',
		'numberposts'      => -1,
		'fields'           => 'ids',
		'suppress_filters' => true,
	)
);

foreach ( $ocs_stories as $ocs_story_id ) {
	wp_delete_post( $ocs_story_id, true );
}

// The videos and posters themselves are attachments in the media library. They
// are deliberately NOT deleted, even in this branch: they are the shop's own
// footage, they may be used elsewhere on the site, and a plugin should not be
// able to empty a business's media library on its way out. Delete them from the
// Media screen if that is genuinely what you want.
