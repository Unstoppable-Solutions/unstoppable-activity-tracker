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
	 * Cleanup: option key used for the atomic claim.
	 *
	 * Claimed via add_option() with autoload=no. Atomic at the MySQL layer via
	 * the UNIQUE index on wp_options.option_name — INSERT either succeeds
	 * (we own the lock) or fails (someone else does), with no TOCTOU window.
	 */
	const CLEANUP_LOCK_OPTION = 'uatr_cleanup_lock';

	/**
	 * Cleanup: how long (seconds) before a stale lock can be reclaimed.
	 *
	 * Longer than any reasonable cron invocation — guards against a PHP
	 * process dying mid-cleanup without releasing the lock.
	 */
	const CLEANUP_LOCK_STALE_AFTER = 600; // 10 minutes.

	/**
	 * Cleanup: rows deleted per batched DELETE.
	 */
	const CLEANUP_BATCH_SIZE = 1000;

	/**
	 * Cleanup: maximum batches per cron invocation.
	 *
	 * 50 batches × 1000 rows = 50,000 rows per run. If more rows still match
	 * the cutoff after the cap, run_cleanup() schedules a follow-up.
	 */
	const CLEANUP_MAX_BATCHES = 50;

	/**
	 * Cleanup: how long to wait before the follow-up run (seconds).
	 */
	const CLEANUP_RESUME_DELAY = 300; // 5 minutes.

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
		// Clear any stale cleanup lock so the next activation starts clean.
		delete_option( self::CLEANUP_LOCK_OPTION );
		delete_site_transient( 'update_plugins' );
		flush_rewrite_rules();
	}

	/* ----------------------------------------------------------------
	 * Cron: retention cleanup
	 * -------------------------------------------------------------- */

	/**
	 * Delete events older than the retention period.
	 *
	 * Bounded cleanup: deletes in LIMIT'd chunks, caps the total work per
	 * cron invocation, and reschedules a follow-up run when work remains.
	 * Concurrent cron invocations are coordinated through an atomic lock
	 * built on the UNIQUE index of wp_options.option_name — a losing worker
	 * skips the run rather than queueing or racing.
	 *
	 * @return int Number of rows deleted in this invocation.
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

		// Atomic claim of the cleanup lock. Returns null if another worker
		// has a live lock, in which case we skip this run.
		$owner = $this->claim_cleanup_lock();
		if ( null === $owner ) {
			return 0;
		}

		$total_deleted = 0;
		$batches_run   = 0;

		try {
			$cutoff = microtime( true ) - ( (float) $retention_days * (float) DAY_IN_SECONDS );
			$table  = UATR_Installer::get_table_name();

			for ( $i = 0; $i < self::CLEANUP_MAX_BATCHES; $i++ ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded retention cleanup on custom table; $table safe (WP prefix + hardcoded), $cutoff and batch size bound via prepare().
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_on < %f LIMIT %d", $cutoff, self::CLEANUP_BATCH_SIZE ) );
				++$batches_run;

				if ( ! is_numeric( $deleted ) || (int) $deleted <= 0 ) {
					// Nothing left to delete — backlog is drained.
					break;
				}

				$total_deleted += (int) $deleted;

				if ( (int) $deleted < self::CLEANUP_BATCH_SIZE ) {
					// Partial batch — no more matching rows after this one.
					break;
				}
			}
		} finally {
			$this->release_cleanup_lock( $owner );
		}

		// If we hit the per-run cap on full batches, more rows may still
		// match the cutoff. Schedule a follow-up run to continue. WordPress
		// dedupes identical cron events within a 10-minute window, so this
		// is safe to call unconditionally.
		if ( $batches_run >= self::CLEANUP_MAX_BATCHES ) {
			wp_schedule_single_event( time() + self::CLEANUP_RESUME_DELAY, UATR_CRON_HOOK );
		}

		return $total_deleted;
	}

	/**
	 * Atomically claim the cleanup lock.
	 *
	 * Uses a direct `INSERT IGNORE` against `wp_options` — truly atomic at
	 * the MySQL layer via the UNIQUE index on `option_name`. The INSERT
	 * either succeeds (we own the lock, 1 row affected) or is ignored
	 * (another worker already holds the lock, 0 rows affected), with no
	 * window between check and set.
	 *
	 * `add_option()` is NOT used here: its implementation does a
	 * `get_option()` pre-check followed by `INSERT … ON DUPLICATE KEY UPDATE`,
	 * which is TOCTOU-vulnerable — two concurrent workers can both pass
	 * the pre-check, and the second `INSERT` turns into an `UPDATE` that
	 * overwrites the first worker's claim while still returning success.
	 * Transients were rejected for the same TOCTOU reason plus their
	 * documented cache-eviction behavior.
	 *
	 * If the claim fails and the existing lock's timestamp is older than
	 * CLEANUP_LOCK_STALE_AFTER, the lock is force-released and the claim
	 * retried once. Guards against a PHP process dying mid-cleanup without
	 * releasing.
	 *
	 * @return string|null Owner UUID if claimed, null if another worker holds a live lock.
	 */
	private function claim_cleanup_lock() {
		global $wpdb;

		$owner = wp_generate_uuid4();
		$value = $owner . '|' . time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic compare-and-swap claim on wp_options. INSERT IGNORE is the primitive we need; add_option() is NOT atomic (pre-check + INSERT ON DUPLICATE KEY UPDATE is TOCTOU-vulnerable and silently overwrites a competing claim).
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::CLEANUP_LOCK_OPTION, $value ) );

		// Keep the WordPress options cache coherent with the direct write.
		wp_cache_delete( self::CLEANUP_LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		if ( 1 === (int) $inserted ) {
			return $owner;
		}

		// Claim failed. Check whether the existing lock is stale.
		$existing = get_option( self::CLEANUP_LOCK_OPTION );
		if ( is_string( $existing ) && false !== strpos( $existing, '|' ) ) {
			$parts = explode( '|', $existing, 2 );
			if ( isset( $parts[1] ) && is_numeric( $parts[1] ) ) {
				$age = time() - (int) $parts[1];
				if ( $age > self::CLEANUP_LOCK_STALE_AFTER ) {
					// Stale — delete the exact stale row and retry the
					// claim once. The option_value match ensures we only
					// delete the row we saw: if another worker already
					// reclaimed in the gap, the DELETE affects 0 rows and
					// our retry INSERT IGNORE fails cleanly.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Stale-lock recovery; matched on exact value so a concurrent reclaim is not clobbered.
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::CLEANUP_LOCK_OPTION, $existing ) );
					wp_cache_delete( self::CLEANUP_LOCK_OPTION, 'options' );

					// Retry the atomic claim.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Retry of atomic claim after stale-lock recovery.
					$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::CLEANUP_LOCK_OPTION, $value ) );
					wp_cache_delete( self::CLEANUP_LOCK_OPTION, 'options' );
					wp_cache_delete( 'notoptions', 'options' );

					if ( 1 === (int) $inserted ) {
						return $owner;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Release the cleanup lock — only if we still own it.
	 *
	 * Atomic owner-checked release: a single DELETE scoped by both the
	 * option name and a LIKE pattern matching the owner-UUID prefix of the
	 * stored value. If another worker already reclaimed as stale and
	 * installed its own claim, the DELETE affects 0 rows — we never
	 * clobber a newer worker's lock.
	 *
	 * @param string $owner Owner UUID returned by claim_cleanup_lock().
	 */
	private function release_cleanup_lock( $owner ) {
		global $wpdb;

		$owner_pattern = $wpdb->esc_like( (string) $owner . '|' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic owner-checked release; matched on our UUID prefix so a newer worker's claim is not clobbered.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::CLEANUP_LOCK_OPTION, $owner_pattern ) );

		wp_cache_delete( self::CLEANUP_LOCK_OPTION, 'options' );
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
