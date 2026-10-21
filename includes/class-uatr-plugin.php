<?php
/**
 * Main plugin coordinator.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton that wires all plugin components together.
 */
class UATR_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var UATR_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Logger.
	 *
	 * @var UATR_Logger
	 */
	public $logger;

	/**
	 * Events tracker.
	 *
	 * @var UATR_Events
	 */
	public $events;

	/**
	 * REST API.
	 *
	 * @var UATR_REST_API
	 */
	public $rest_api;

	/**
	 * Admin handler.
	 *
	 * @var UATR_Admin
	 */
	public $admin;

	/**
	 * Notices.
	 *
	 * @var UATR_Admin_Notices
	 */
	public $notices;

	/**
	 * Insights page.
	 *
	 * @var UATR_Insights
	 */
	public $insights;

	/**
	 * WooCommerce events bridge (no-op if WC isn't active).
	 *
	 * @var UATR_WC_Events
	 */
	public $wc_events;

	/**
	 * CSV / JSON exporter.
	 *
	 * @var UATR_Exporter
	 */
	public $exporter;

	/**
	 * Email digest handler.
	 *
	 * @var UATR_Digest
	 */
	public $digest;

	/**
	 * Get singleton.
	 *
	 * @return UATR_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		// Run a delayed install check so a manual table-drop self-heals on next pageload.
		add_action( 'plugins_loaded', array( 'UATR_Installer', 'maybe_install' ) );

		// Core: logger + event hooks. Always loaded.
		$this->logger = new UATR_Logger();
		$this->events = new UATR_Events( $this->logger );

		// WooCommerce events — class is loaded unconditionally, but it only wires hooks
		// if WooCommerce itself is active. Deferred to plugins_loaded so WC has had a chance
		// to declare itself.
		add_action( 'plugins_loaded', array( $this, 'init_wc_events' ), 20 );

		// REST.
		$this->rest_api = new UATR_REST_API();

		// Email digest cron handler (and option-change listeners) — always loaded so cron fires
		// even when nobody is in the admin.
		$this->digest = new UATR_Digest( $this->logger );

		// Cron callback.
		add_action( UATR_CRON_HOOK, array( $this, 'run_cleanup' ) );

		// Admin-only.
		if ( is_admin() ) {
			$this->notices  = new UATR_Admin_Notices();
			$this->insights = new UATR_Insights();
			$this->exporter = new UATR_Exporter();
			$this->admin    = new UATR_Admin( $this->notices );
		}
	}

	/**
	 * Instantiate the WooCommerce events bridge (no-op on non-WC sites).
	 */
	public function init_wc_events() {
		$this->wc_events = new UATR_WC_Events( $this->logger );
	}

	/* ----------------------------------------------------------------
	 * Activation / deactivation
	 * -------------------------------------------------------------- */

	/**
	 * Activation hook.
	 */
	public static function activate() {
		// Create / upgrade the table.
		UATR_Installer::install();
		update_option( UATR_OPTION_PREFIX . '_db_version', UATR_DB_VERSION );

		// Generate API key on first activation only.
		if ( ! get_option( UATR_OPTION_PREFIX . '_api_key' ) ) {
			update_option( UATR_OPTION_PREFIX . '_api_key', self::generate_api_key() );
		}
		if ( false === get_option( UATR_OPTION_PREFIX . '_api_enabled' ) ) {
			update_option( UATR_OPTION_PREFIX . '_api_enabled', '1' );
		}
		if ( false === get_option( UATR_OPTION_PREFIX . '_retention_days' ) ) {
			update_option( UATR_OPTION_PREFIX . '_retention_days', 90 );
		}
		if ( ! get_option( UATR_OPTION_PREFIX . '_install_date' ) ) {
			update_option( UATR_OPTION_PREFIX . '_install_date', time() );
		}

		// Digest defaults — opt-in, so disabled by default. Seed the other settings so
		// the UI doesn't show blank controls on first activation.
		if ( false === get_option( 'uatr_digest_enabled' ) ) {
			update_option( 'uatr_digest_enabled', '0' );
		}
		if ( false === get_option( 'uatr_digest_email' ) ) {
			update_option( 'uatr_digest_email', (string) get_option( 'admin_email', '' ) );
		}
		if ( false === get_option( 'uatr_digest_frequency' ) ) {
			update_option( 'uatr_digest_frequency', 'daily' );
		}
		if ( false === get_option( 'uatr_digest_threshold' ) ) {
			update_option( 'uatr_digest_threshold', 'warning' );
		}

		// Schedule retention cron (daily).
		if ( ! wp_next_scheduled( UATR_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', UATR_CRON_HOOK );
		}

		// Schedule digest cron if the feature is enabled (on reactivation after settings exist).
		UATR_Digest::maybe_schedule();

		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook — clear caches and cron, but keep options + table data.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( UATR_CRON_HOOK );
		UATR_Digest::clear_schedule();
		delete_site_transient( 'update_plugins' );
		flush_rewrite_rules();
	}

	/* ----------------------------------------------------------------
	 * Cron: retention cleanup
	 * -------------------------------------------------------------- */

	/**
	 * Delete events older than the retention period.
	 *
	 * @return int Number of rows deleted.
	 */
	public function run_cleanup() {
		global $wpdb;

		if ( ! UATR_Installer::table_exists() ) {
			return 0;
		}

		$retention_days = (int) get_option( UATR_OPTION_PREFIX . '_retention_days', 90 );
		if ( $retention_days <= 0 ) {
			// 0 or negative = keep forever.
			return 0;
		}

		$cutoff = microtime( true ) - ( (float) $retention_days * (float) DAY_IN_SECONDS );
		$table  = UATR_Installer::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Retention cleanup on custom table; $table safe (WP prefix + hardcoded), $cutoff bound via prepare().
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_on < %f", $cutoff ) );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/* ----------------------------------------------------------------
	 * Helpers (static, used by other classes)
	 * -------------------------------------------------------------- */

	/**
	 * Generate a fresh prefixed API key.
	 *
	 * @return string
	 */
	public static function generate_api_key() {
		return 'uatr_' . wp_generate_password( 24, false, false );
	}

	/**
	 * Stored API key.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		return (string) get_option( UATR_OPTION_PREFIX . '_api_key', '' );
	}

	/**
	 * Whether the API endpoint is enabled.
	 *
	 * @return bool
	 */
	public static function is_api_enabled() {
		return '1' === (string) get_option( UATR_OPTION_PREFIX . '_api_enabled', '1' );
	}

	/**
	 * Mask an API key for display.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function mask_key( $key ) {
		$key = (string) $key;
		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
	}

	/**
	 * Get oldest event timestamp (or null if none).
	 *
	 * @return float|null
	 */
	public static function get_oldest_event_time() {
		global $wpdb;
		if ( ! UATR_Installer::table_exists() ) {
			return null;
		}
		$table = UATR_Installer::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate MIN() on custom table; identifier is safe.
		$value = $wpdb->get_var( "SELECT MIN(created_on) FROM {$table}" );
		return null === $value ? null : (float) $value;
	}
}
