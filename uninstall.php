<?php
/**
 * Uninstall handler — runs only when the plugin is deleted from the Plugins list,
 * NOT on deactivation.
 *
 * Deletes:
 *   - Custom events table
 *   - All plugin options
 *   - All plugin transients
 *   - Scheduled cron events
 *
 * @package Unstoppable_Activity_Tracker
 */

// WordPress sets this constant when uninstalling. Bail otherwise.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop our custom events table.
// Table name is safe: $wpdb->prefix is controlled by WordPress + a hardcoded suffix.
// DROP TABLE cannot use placeholders for identifiers, so interpolation is required.
$uatr_table = $wpdb->prefix . 'uatr_events';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safe (WP prefix + hardcoded string); schema change on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$uatr_table}" );

// Delete all plugin options.
$uatr_options = array(
	'uatr_db_version',
	'uatr_api_key',
	'uatr_api_enabled',
	'uatr_install_date',
	'uatr_retention_days',
	'uatr_digest_enabled',
	'uatr_digest_email',
	'uatr_digest_frequency',
	'uatr_digest_threshold',
	'uatr_digest_last_sent',
	'uatr_cleanup_lock',
);
foreach ( $uatr_options as $uatr_opt ) {
	delete_option( $uatr_opt );
}

// Clear transients.
delete_transient( 'uatr_flash_notices' );

// Unschedule crons.
wp_clear_scheduled_hook( 'uatr_daily_cleanup' );
wp_clear_scheduled_hook( 'uatr_send_digest' );
