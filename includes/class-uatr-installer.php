<?php
/**
 * DB schema installer.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the custom events table.
 */
class UATR_Installer {

	/**
	 * Get the fully-qualified table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . UATR_TABLE_SUFFIX;
	}

	/**
	 * Run the install if the stored DB version doesn't match the code's DB version.
	 */
	public static function maybe_install() {
		$installed_version = get_option( UATR_OPTION_PREFIX . '_db_version' );
		if ( UATR_DB_VERSION !== $installed_version ) {
			self::install();
			update_option( UATR_OPTION_PREFIX . '_db_version', UATR_DB_VERSION );
		}
	}

	/**
	 * Create / upgrade the events table.
	 *
	 * Note on dbDelta requirements:
	 *   - one column per line
	 *   - two spaces between PRIMARY KEY and the column name
	 *   - KEY (not INDEX)
	 *   - column type and constraints in lowercase or matching exactly between calls.
	 */
	public static function install() {
		global $wpdb;

		$table   = self::get_table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_on DOUBLE NOT NULL,
			alert_id INT UNSIGNED NOT NULL DEFAULT 0,
			severity VARCHAR(20) NOT NULL DEFAULT 'info',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			username VARCHAR(60) NOT NULL DEFAULT '',
			user_roles VARCHAR(255) NOT NULL DEFAULT '',
			object VARCHAR(50) NOT NULL DEFAULT '',
			event_type VARCHAR(50) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			ua VARCHAR(500) NOT NULL DEFAULT '',
			message TEXT NOT NULL,
			post_title VARCHAR(255) NOT NULL DEFAULT '',
			post_type VARCHAR(50) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_on (created_on),
			KEY user_id (user_id),
			KEY alert_id (alert_id),
			KEY object_event (object, event_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the events table. Called only from uninstall.php.
	 */
	public static function drop_table() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safe (WP prefix + hardcoded suffix); DROP TABLE cannot use placeholders for identifiers.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Convenience: number of rows currently in the events table.
	 *
	 * @return int
	 */
	public static function count_events() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate count on custom table; identifier is safe (WP prefix + hardcoded suffix).
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Whether the events table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES check; $table passed as placeholder value.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
