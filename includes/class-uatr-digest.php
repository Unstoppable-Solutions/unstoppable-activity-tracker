<?php
/**
 * Email digest — periodic summary of important events.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled email summary of recent activity, filtered by severity threshold.
 *
 * Opt-in: disabled by default. When enabled, runs on a WP cron (daily or weekly)
 * and emails a plain-text summary of events that meet or exceed the configured
 * severity threshold to the configured recipient.
 */
class UATR_Digest {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'uatr_send_digest';

	/**
	 * Logger reference (for sending ourselves a self-event when digest fires).
	 *
	 * @var UATR_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param UATR_Logger $logger Logger.
	 */
	public function __construct( UATR_Logger $logger ) {
		$this->logger = $logger;

		// Cron handler.
		add_action( self::CRON_HOOK, array( $this, 'send_digest' ) );

		// Reschedule cron if the frequency setting changes.
		add_action( 'update_option_uatr_digest_frequency', array( $this, 'reschedule_cron' ), 10, 2 );
		add_action( 'update_option_uatr_digest_enabled', array( $this, 'reschedule_cron_on_enable' ), 10, 2 );
	}

	/* ================================================================
	 * Public helpers
	 * ================================================================ */

	/**
	 * Schedule the cron if digest is enabled and no event is pending.
	 */
	public static function maybe_schedule() {
		if ( ! self::is_enabled() ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$recurrence = ( 'weekly' === self::get_frequency() ) ? 'weekly' : 'daily';

		// Fire at ~8am tomorrow in the site's timezone, in the chosen cadence.
		$now        = time();
		$tz         = wp_timezone();
		$next       = new DateTimeImmutable( '@' . $now );
		$next       = $next->setTimezone( $tz );
		$next_8am   = $next->setTime( 8, 0, 0 );
		$next_ts    = $next_8am->getTimestamp();
		if ( $next_ts <= $now ) {
			$next_ts += DAY_IN_SECONDS;
		}

		wp_schedule_event( $next_ts, $recurrence, self::CRON_HOOK );
	}

	/**
	 * Clear the digest cron (e.g. on deactivation / uninstall).
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Is the digest feature enabled?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '1' === (string) get_option( 'uatr_digest_enabled', '0' );
	}

	/**
	 * Configured recipient email, falling back to admin email.
	 *
	 * @return string
	 */
	public static function get_recipient() {
		$email = (string) get_option( 'uatr_digest_email', '' );
		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email', '' );
		}
		return $email;
	}

	/**
	 * Frequency: daily or weekly.
	 *
	 * @return string
	 */
	public static function get_frequency() {
		$f = (string) get_option( 'uatr_digest_frequency', 'daily' );
		return ( 'weekly' === $f ) ? 'weekly' : 'daily';
	}

	/**
	 * Severity threshold: 'warning', 'error', or 'critical'.
	 *
	 * @return string
	 */
	public static function get_threshold() {
		$t = (string) get_option( 'uatr_digest_threshold', 'warning' );
		if ( ! in_array( $t, array( 'warning', 'error', 'critical' ), true ) ) {
			$t = 'warning';
		}
		return $t;
	}

	/**
	 * Which severities to include given the threshold.
	 *
	 * @return string[]
	 */
	public static function get_included_severities() {
		$threshold = self::get_threshold();
		$ladder    = array( 'info', 'notice', 'warning', 'error', 'critical' );
		$idx       = array_search( $threshold, $ladder, true );
		if ( false === $idx ) {
			$idx = 2; // warning
		}
		return array_slice( $ladder, $idx );
	}

	/* ================================================================
	 * Cron handler
	 * ================================================================ */

	/**
	 * Send the digest email. Fires on wp_schedule_event cron.
	 */
	public function send_digest() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$recipient = self::get_recipient();
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return;
		}

		if ( ! UATR_Installer::table_exists() ) {
			return;
		}

		// Rate-limit: don't re-send if we already sent within the last 23 hours.
		$last_sent = (int) get_option( 'uatr_digest_last_sent', 0 );
		if ( $last_sent > 0 && ( time() - $last_sent ) < ( 23 * HOUR_IN_SECONDS ) ) {
			return;
		}

		// Window: last 24h for daily, last 7d for weekly.
		$window_seconds = ( 'weekly' === self::get_frequency() ) ? ( 7 * DAY_IN_SECONDS ) : DAY_IN_SECONDS;
		$window_start   = microtime( true ) - $window_seconds;

		$severities = self::get_included_severities();
		$data       = $this->gather_digest_data( $window_start, $severities );

		// If there's nothing to report, skip the email entirely rather than send an empty digest.
		if ( 0 === $data['total'] ) {
			update_option( 'uatr_digest_last_sent', time() );
			return;
		}

		$subject = $this->build_subject( $data, $window_seconds );
		$body    = $this->build_body( $data, $window_seconds, $severities );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $recipient, $subject, $body, $headers );

		update_option( 'uatr_digest_last_sent', time() );

		if ( $sent && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[UATR Digest] Sent digest to %s (%d events).', $recipient, $data['total'] ) );
		}
	}

	/**
	 * Gather all the data needed for the digest email.
	 *
	 * @param float    $window_start Window start (Unix with microseconds).
	 * @param string[] $severities   Severities to include.
	 * @return array{total:int, by_severity:array, top_events:array[]}
	 */
	private function gather_digest_data( $window_start, $severities ) {
		global $wpdb;
		$table = UATR_Installer::get_table_name();

		$placeholders = implode( ', ', array_fill( 0, count( $severities ), '%s' ) );

		// Severity counts.
		$count_params = array_merge( array( $window_start ), $severities );
		$count_sql    = "SELECT severity, COUNT(*) AS c FROM {$table} WHERE created_on > %f AND severity IN ($placeholders) GROUP BY severity ORDER BY c DESC";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Digest aggregate; identifier safe, severities bound via %s placeholders.
		$count_rows = (array) $wpdb->get_results( $wpdb->prepare( $count_sql, $count_params ), ARRAY_A );

		$by_severity = array();
		$total       = 0;
		foreach ( $count_rows as $row ) {
			$by_severity[ (string) $row['severity'] ] = (int) $row['c'];
			$total                                    += (int) $row['c'];
		}

		// Top 10 events (most recent events matching the threshold).
		$top_params = array_merge( array( $window_start ), $severities );
		$top_sql    = "SELECT created_on, severity, username, object, event_type, message, ip FROM {$table} WHERE created_on > %f AND severity IN ($placeholders) ORDER BY created_on DESC LIMIT 10";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Digest preview; identifier safe, severities bound via %s placeholders.
		$top_rows = (array) $wpdb->get_results( $wpdb->prepare( $top_sql, $top_params ), ARRAY_A );

		return array(
			'total'       => $total,
			'by_severity' => $by_severity,
			'top_events'  => $top_rows,
		);
	}

	/**
	 * Build the subject line.
	 *
	 * @param array $data           Digest data.
	 * @param int   $window_seconds Window size.
	 * @return string
	 */
	private function build_subject( $data, $window_seconds ) {
		$site   = (string) get_bloginfo( 'name' );
		$period = ( DAY_IN_SECONDS === $window_seconds ) ? __( 'Daily', 'unstoppable-activity-tracker' ) : __( 'Weekly', 'unstoppable-activity-tracker' );
		return sprintf(
			/* translators: 1: period (Daily/Weekly), 2: site name, 3: total event count */
			__( '[%1$s Activity Digest] %2$s — %3$d events', 'unstoppable-activity-tracker' ),
			$period,
			$site,
			(int) $data['total']
		);
	}

	/**
	 * Build the plain-text email body.
	 *
	 * @param array    $data           Digest data.
	 * @param int      $window_seconds Window size.
	 * @param string[] $severities     Included severities.
	 * @return string
	 */
	private function build_body( $data, $window_seconds, $severities ) {
		$site     = (string) get_bloginfo( 'name' );
		$site_url = home_url();
		$period   = ( DAY_IN_SECONDS === $window_seconds ) ? __( 'last 24 hours', 'unstoppable-activity-tracker' ) : __( 'last 7 days', 'unstoppable-activity-tracker' );
		$tz       = wp_timezone();

		$lines = array();
		$lines[] = sprintf(
			/* translators: 1: site name, 2: period */
			__( 'Activity digest for %1$s (%2$s)', 'unstoppable-activity-tracker' ),
			$site,
			$period
		);
		$lines[] = str_repeat( '=', 60 );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: event count */
			__( 'Total events at or above threshold: %d', 'unstoppable-activity-tracker' ),
			(int) $data['total']
		);
		$lines[] = sprintf(
			/* translators: %s: list of severities */
			__( 'Severities included: %s', 'unstoppable-activity-tracker' ),
			implode( ', ', $severities )
		);
		$lines[] = '';

		// Counts by severity.
		if ( ! empty( $data['by_severity'] ) ) {
			$lines[] = __( 'Breakdown:', 'unstoppable-activity-tracker' );
			foreach ( $data['by_severity'] as $sev => $count ) {
				$lines[] = sprintf( '  %-10s %d', $sev, (int) $count );
			}
			$lines[] = '';
		}

		// Recent events.
		if ( ! empty( $data['top_events'] ) ) {
			$lines[] = __( 'Most recent events:', 'unstoppable-activity-tracker' );
			$lines[] = str_repeat( '-', 60 );
			foreach ( $data['top_events'] as $ev ) {
				$ts   = (int) $ev['created_on'];
				$dt   = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
				$when = $dt->format( 'M j, H:i' );
				$user = '' !== (string) $ev['username'] ? $ev['username'] : '(system)';
				$lines[] = sprintf(
					'[%s] %s · %s · %s/%s',
					strtoupper( (string) $ev['severity'] ),
					$when,
					$user,
					(string) $ev['object'],
					(string) $ev['event_type']
				);
				$lines[] = '    ' . (string) $ev['message'];
				if ( ! empty( $ev['ip'] ) ) {
					$lines[] = '    IP: ' . (string) $ev['ip'];
				}
				$lines[] = '';
			}
		}

		$lines[] = str_repeat( '=', 60 );
		$lines[] = __( 'View the full activity log:', 'unstoppable-activity-tracker' );
		$lines[] = admin_url( 'admin.php?page=' . UATR_PLUGIN_SLUG . '-log' );
		$lines[] = '';
		$lines[] = __( 'Change digest settings:', 'unstoppable-activity-tracker' );
		$lines[] = admin_url( 'admin.php?page=' . UATR_PLUGIN_SLUG . '-settings' );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: site URL */
			__( 'This digest was sent by Unstoppable Activity Tracker on %s.', 'unstoppable-activity-tracker' ),
			$site_url
		);

		return implode( "\n", $lines );
	}

	/* ================================================================
	 * Reschedule on option change
	 * ================================================================ */

	/**
	 * When the enabled toggle changes, (re)schedule or clear the cron.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 */
	public function reschedule_cron_on_enable( $old_value, $new_value ) {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}
		if ( '1' === (string) $new_value ) {
			self::maybe_schedule();
		} else {
			self::clear_schedule();
		}
	}

	/**
	 * When frequency changes, clear and re-schedule so it picks up the new recurrence.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 */
	public function reschedule_cron( $old_value, $new_value ) {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}
		self::clear_schedule();
		if ( self::is_enabled() ) {
			self::maybe_schedule();
		}
	}
}
