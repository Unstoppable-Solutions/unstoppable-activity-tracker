<?php
/**
 * Insights page — visual summaries and reports.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Insights admin page and serves its data via AJAX.
 *
 * All data comes from the plugin's own uatr_events table — no new tracking,
 * no new tables, just focused aggregate queries over an existing dataset.
 */
class UATR_Insights {

	/**
	 * Constructor — wires up AJAX handler.
	 */
	public function __construct() {
		add_action( 'wp_ajax_uatr_get_insights', array( $this, 'ajax_get_insights' ) );
	}

	/* ================================================================
	 * Page renderer
	 * ================================================================ */

	/**
	 * Render the Insights page.
	 *
	 * The page is intentionally shell-only: real data comes from AJAX so that
	 * the time-range switcher can update all four cards without a page reload.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-activity-tracker' ) );
		}

		$event_count = UATR_Installer::table_exists() ? UATR_Installer::count_events() : 0;
		?>
		<div class="wrap uatr-wrap uatr-insights-wrap">
			<h1><?php esc_html_e( 'Insights', 'unstoppable-activity-tracker' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Visual summaries of the activity recorded on this site.', 'unstoppable-activity-tracker' ); ?>
			</p>

			<?php if ( 0 === $event_count ) : ?>
				<div class="uatr-insights-empty">
					<p><strong><?php esc_html_e( 'No events recorded yet.', 'unstoppable-activity-tracker' ); ?></strong></p>
					<p><?php esc_html_e( 'Activity will appear here as it happens: logins, content changes, plugin and user activity, and more. Check back after normal site use for a few hours or days.', 'unstoppable-activity-tracker' ); ?></p>
				</div>
			<?php else : ?>

				<div class="uatr-range-selector" role="tablist" aria-label="<?php esc_attr_e( 'Time range', 'unstoppable-activity-tracker' ); ?>">
					<button type="button" class="button uatr-range-btn" data-range="24h"><?php esc_html_e( '24 hours', 'unstoppable-activity-tracker' ); ?></button>
					<button type="button" class="button uatr-range-btn uatr-range-active" data-range="7d"><?php esc_html_e( '7 days', 'unstoppable-activity-tracker' ); ?></button>
					<button type="button" class="button uatr-range-btn" data-range="30d"><?php esc_html_e( '30 days', 'unstoppable-activity-tracker' ); ?></button>
					<button type="button" class="button uatr-range-btn" data-range="90d"><?php esc_html_e( '90 days', 'unstoppable-activity-tracker' ); ?></button>
					<span class="uatr-range-loading" aria-live="polite"></span>
				</div>

				<div class="uatr-insights-grid">

					<div class="uatr-card uatr-card-wide">
						<h2 class="uatr-card-title"><?php esc_html_e( 'Events over time', 'unstoppable-activity-tracker' ); ?></h2>
						<p class="uatr-card-sub"><?php esc_html_e( 'How activity has trended across the selected window.', 'unstoppable-activity-tracker' ); ?></p>
						<div class="uatr-chart-container"><canvas id="uatr-chart-timeline" aria-label="<?php esc_attr_e( 'Timeline of events per day', 'unstoppable-activity-tracker' ); ?>"></canvas></div>
					</div>

					<div class="uatr-card">
						<h2 class="uatr-card-title"><?php esc_html_e( 'Events by category', 'unstoppable-activity-tracker' ); ?></h2>
						<p class="uatr-card-sub"><?php esc_html_e( "What's driving activity: users, posts, plugins, themes, settings, or core.", 'unstoppable-activity-tracker' ); ?></p>
						<div class="uatr-chart-container uatr-chart-donut"><canvas id="uatr-chart-category" aria-label="<?php esc_attr_e( 'Event breakdown by object type', 'unstoppable-activity-tracker' ); ?>"></canvas></div>
					</div>

					<div class="uatr-card">
						<h2 class="uatr-card-title"><?php esc_html_e( 'Most active users', 'unstoppable-activity-tracker' ); ?></h2>
						<p class="uatr-card-sub"><?php esc_html_e( 'Top 5 users by event count. Useful for spotting compromised accounts.', 'unstoppable-activity-tracker' ); ?></p>
						<div id="uatr-top-users" class="uatr-top-users-container">
							<p class="uatr-loading"><?php esc_html_e( 'Loading…', 'unstoppable-activity-tracker' ); ?></p>
						</div>
					</div>

					<div class="uatr-card uatr-card-wide">
						<h2 class="uatr-card-title"><?php esc_html_e( 'Security highlights', 'unstoppable-activity-tracker' ); ?></h2>
						<p class="uatr-card-sub"><?php esc_html_e( 'Notable events for security and compliance review.', 'unstoppable-activity-tracker' ); ?></p>
						<div id="uatr-security-highlights" class="uatr-security-container">
							<p class="uatr-loading"><?php esc_html_e( 'Loading…', 'unstoppable-activity-tracker' ); ?></p>
						</div>
					</div>

				</div>

			<?php endif; ?>
		</div>
		<?php
	}

	/* ================================================================
	 * AJAX endpoint
	 * ================================================================ */

	/**
	 * AJAX: return all insights data for a given range in one payload.
	 */
	public function ajax_get_insights() {
		check_ajax_referer( 'uatr_insights', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'unstoppable-activity-tracker' ) ), 403 );
		}

		if ( ! UATR_Installer::table_exists() ) {
			wp_send_json_error( array( 'message' => __( 'Events table is missing.', 'unstoppable-activity-tracker' ) ), 503 );
		}

		$range        = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '7d';
		$range_seconds = $this->range_to_seconds( $range );
		$now          = microtime( true );
		$range_start  = $now - $range_seconds;

		wp_send_json_success( array(
			'range'              => $range,
			'range_start'        => $range_start,
			'range_end'          => $now,
			'events_over_time'   => $this->get_events_over_time( $range_start, $range_seconds ),
			'by_category'        => $this->get_by_category( $range_start ),
			'top_users'          => $this->get_top_users( $range_start ),
			'security_highlights' => $this->get_security_highlights( $range_start ),
		) );
	}

	/**
	 * Convert a range key to seconds.
	 *
	 * @param string $range One of: 24h, 7d, 30d, 90d.
	 * @return int
	 */
	private function range_to_seconds( $range ) {
		switch ( $range ) {
			case '24h':
				return DAY_IN_SECONDS;
			case '30d':
				return 30 * DAY_IN_SECONDS;
			case '90d':
				return 90 * DAY_IN_SECONDS;
			case '7d':
			default:
				return 7 * DAY_IN_SECONDS;
		}
	}

	/* ================================================================
	 * Data queries
	 * ================================================================ */

	/**
	 * Card 1: Events per day (or per hour for the 24h range).
	 *
	 * Returns a sorted array of buckets with zero-fill so charts don't have gaps.
	 *
	 * @param float $range_start   Range start (Unix timestamp with microseconds).
	 * @param int   $range_seconds Range width in seconds (governs bucket size).
	 * @return array<array{label: string, count: int}>
	 */
	private function get_events_over_time( $range_start, $range_seconds ) {
		global $wpdb;
		$table = UATR_Installer::get_table_name();

		// For 24h: hourly buckets (24 points). For everything else: daily buckets.
		$bucket_seconds = ( DAY_IN_SECONDS === $range_seconds ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate on custom table; identifier is safe, values bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT FLOOR(created_on / %d) AS bucket, COUNT(*) AS c FROM {$table} WHERE created_on > %f GROUP BY bucket ORDER BY bucket ASC", $bucket_seconds, $range_start ), ARRAY_A );

		// Index rows by bucket for O(1) lookup during zero-fill.
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['bucket'] ] = (int) $row['c'];
		}

		// Zero-fill: emit a bucket for every step in the range so charts don't skip empty days.
		$out          = array();
		$first_bucket = (int) floor( $range_start / $bucket_seconds );
		$last_bucket  = (int) floor( microtime( true ) / $bucket_seconds );
		$tz           = wp_timezone();
		$format       = ( HOUR_IN_SECONDS === $bucket_seconds ) ? 'M j, ga' : 'M j';

		for ( $b = $first_bucket; $b <= $last_bucket; $b++ ) {
			$bucket_ts     = $b * $bucket_seconds;
			$dt            = new DateTimeImmutable( '@' . $bucket_ts );
			$dt            = $dt->setTimezone( $tz );
			$out[] = array(
				'label' => $dt->format( $format ),
				'count' => isset( $counts[ $b ] ) ? $counts[ $b ] : 0,
			);
		}

		return $out;
	}

	/**
	 * Card 2: Events grouped by object type (user, post, plugin, theme, option, core).
	 *
	 * @param float $range_start Range start.
	 * @return array<array{object: string, count: int}>
	 */
	private function get_by_category( $range_start ) {
		global $wpdb;
		$table = UATR_Installer::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate on custom table; identifier is safe, value bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object, COUNT(*) AS c FROM {$table} WHERE created_on > %f GROUP BY object ORDER BY c DESC", $range_start ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$obj = (string) $row['object'];
			if ( '' === $obj ) {
				$obj = 'other';
			}
			$out[] = array(
				'object' => $obj,
				'count'  => (int) $row['c'],
			);
		}
		return $out;
	}

	/**
	 * Card 3: Top 5 users by event count.
	 *
	 * @param float $range_start Range start.
	 * @return array<array{user_id: int, username: string, event_count: int, last_active: float}>
	 */
	private function get_top_users( $range_start ) {
		global $wpdb;
		$table = UATR_Installer::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate on custom table; identifier is safe, value bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, MAX(username) AS username, COUNT(*) AS c, MAX(created_on) AS last_active FROM {$table} WHERE created_on > %f AND user_id > 0 GROUP BY user_id ORDER BY c DESC LIMIT 5", $range_start ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'user_id'     => (int) $row['user_id'],
				'username'    => (string) $row['username'],
				'event_count' => (int) $row['c'],
				'last_active' => (float) $row['last_active'],
			);
		}
		return $out;
	}

	/**
	 * Card 4: Security-relevant counts by specific event codes.
	 *
	 * Uses a single query with SUM(CASE) so it's just one round-trip.
	 *
	 * @param float $range_start Range start.
	 * @return array<string,int>
	 */
	private function get_security_highlights( $range_start ) {
		global $wpdb;
		$table = UATR_Installer::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate on custom table; identifier is safe, value bound via prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT
			SUM(CASE WHEN alert_id = %d THEN 1 ELSE 0 END) AS failed_logins,
			SUM(CASE WHEN alert_id = %d THEN 1 ELSE 0 END) AS new_users,
			SUM(CASE WHEN alert_id = %d THEN 1 ELSE 0 END) AS deleted_users,
			SUM(CASE WHEN alert_id = %d THEN 1 ELSE 0 END) AS role_changes,
			SUM(CASE WHEN alert_id = %d THEN 1 ELSE 0 END) AS plugin_activations,
			SUM(CASE WHEN alert_id = %d THEN 1 ELSE 0 END) AS plugin_deactivations,
			SUM(CASE WHEN alert_id = %d THEN 1 ELSE 0 END) AS plugin_installs,
			SUM(CASE WHEN alert_id = %d THEN 1 ELSE 0 END) AS core_updates
			FROM {$table} WHERE created_on > %f",
			UATR_Logger::EVENT_USER_LOGIN_FAILED,
			UATR_Logger::EVENT_USER_REGISTERED,
			UATR_Logger::EVENT_USER_DELETED,
			UATR_Logger::EVENT_USER_ROLE_CHANGED,
			UATR_Logger::EVENT_PLUGIN_ACTIVATED,
			UATR_Logger::EVENT_PLUGIN_DEACTIVATED,
			UATR_Logger::EVENT_PLUGIN_INSTALLED,
			UATR_Logger::EVENT_CORE_UPDATED,
			$range_start
		), ARRAY_A );

		if ( ! $row ) {
			$row = array();
		}

		return array(
			'failed_logins'        => (int) ( isset( $row['failed_logins'] ) ? $row['failed_logins'] : 0 ),
			'new_users'            => (int) ( isset( $row['new_users'] ) ? $row['new_users'] : 0 ),
			'deleted_users'        => (int) ( isset( $row['deleted_users'] ) ? $row['deleted_users'] : 0 ),
			'role_changes'         => (int) ( isset( $row['role_changes'] ) ? $row['role_changes'] : 0 ),
			'plugin_activations'   => (int) ( isset( $row['plugin_activations'] ) ? $row['plugin_activations'] : 0 ),
			'plugin_deactivations' => (int) ( isset( $row['plugin_deactivations'] ) ? $row['plugin_deactivations'] : 0 ),
			'plugin_installs'      => (int) ( isset( $row['plugin_installs'] ) ? $row['plugin_installs'] : 0 ),
			'core_updates'         => (int) ( isset( $row['core_updates'] ) ? $row['core_updates'] : 0 ),
		);
	}
}
