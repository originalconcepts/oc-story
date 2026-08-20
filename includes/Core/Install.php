<?php
/**
 * Installation, database schema and upgrades.
 *
 * @package OC_Story
 */

namespace OCS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every table this plugin creates.
 *
 * Stories are a custom post type and their slides live in one JSON meta key, so
 * there is no table for either — a story renders from a single meta read. These
 * three tables hold only what post meta models badly: the reverse product index,
 * pre-aggregated statistics, and in-flight upload sessions.
 */
class Install {

	const DB_VERSION_OPTION = 'ocs_db_version';
	const DB_VERSION        = 4;

	/**
	 * Every table we own, without the prefix. Used by create and by uninstall.
	 *
	 * @var string[]
	 */
	const TABLES = array( 'slide_product', 'stats_daily', 'uploads' );

	/**
	 * Table name helper.
	 *
	 * @param string $name Short table name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'ocs_' . $name;
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::create_tables();
		Settings::install_defaults();

		if ( class_exists( \OCS\Model\Story::class ) ) {
			\OCS\Model\Story::register();
		}
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'ocs_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ocs_daily_maintenance' );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		set_transient( 'ocs_show_welcome', 1, DAY_IN_SECONDS * 30 );
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		$timestamp = wp_next_scheduled( 'ocs_daily_maintenance' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ocs_daily_maintenance' );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'oc-story' );
		}
	}

	/**
	 * Run schema upgrades when the stored version is behind.
	 */
	public static function maybe_upgrade() {
		$stored = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $stored === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		Settings::install_defaults();

		// v2: the placements option became autoloaded — it is read on every
		// front-end request. update_option() does not change the autoload flag
		// of an unchanged value, so it is re-added.
		if ( $stored < 2 ) {
			$placements = get_option( \OCS\Model\Placement::OPTION, array() );
			delete_option( \OCS\Model\Placement::OPTION );
			add_option( \OCS\Model\Placement::OPTION, $placements, '', true );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create or update every table via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$map     = self::table( 'slide_product' );
		$stats   = self::table( 'stats_daily' );
		$uploads = self::table( 'uploads' );

		// The reverse index. Without it, "which stories mention product 812" is a
		// meta_query across every story on every product page we render.
		$sql = array();

		$sql[] = "CREATE TABLE {$map} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			story_id BIGINT UNSIGNED NOT NULL,
			slide_id VARCHAR(16) NOT NULL DEFAULT '',
			product_id BIGINT UNSIGNED NOT NULL,
			sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY product_story (product_id, story_id),
			KEY story (story_id)
		) {$charset};";

		// Pre-aggregated. A row per view would grow without bound on a busy shop
		// and buy us nothing we cannot get from these counters.
		$sql[] = "CREATE TABLE {$stats} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			day DATE NOT NULL,
			story_id BIGINT UNSIGNED NOT NULL,
			slide_id VARCHAR(16) NOT NULL DEFAULT '',
			surface VARCHAR(20) NOT NULL DEFAULT '',
			device VARCHAR(10) NOT NULL DEFAULT '',
			impressions INT UNSIGNED NOT NULL DEFAULT 0,
			opens INT UNSIGNED NOT NULL DEFAULT 0,
			completions INT UNSIGNED NOT NULL DEFAULT 0,
			product_taps INT UNSIGNED NOT NULL DEFAULT 0,
			sparks INT UNSIGNED NOT NULL DEFAULT 0,
			likes INT UNSIGNED NOT NULL DEFAULT 0,
			add_to_cart INT UNSIGNED NOT NULL DEFAULT 0,
			orders INT UNSIGNED NOT NULL DEFAULT 0,
			revenue DECIMAL(18,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket (day, story_id, slide_id, surface, device),
			KEY day_story (day, story_id)
		) {$charset};";

		// In-flight chunked uploads. Own lifecycle, own expiry, swept daily.
		$sql[] = "CREATE TABLE {$uploads} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session CHAR(32) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			filename VARCHAR(255) NOT NULL DEFAULT '',
			mime VARCHAR(80) NOT NULL DEFAULT '',
			size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			chunk_size INT UNSIGNED NOT NULL DEFAULT 0,
			chunks_total INT UNSIGNED NOT NULL DEFAULT 0,
			chunks_done INT UNSIGNED NOT NULL DEFAULT 0,
			tmp_path VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY session (session),
			KEY expires (expires_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
