<?php
/**
 * CSV / JSON export of events.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams a CSV or JSON export of events, respecting the admin log page filters.
 *
 * Hard cap of 50,000 rows per export to prevent runaway queries.
 */
class UATR_Exporter {

	/**
	 * Maximum rows per export.
	 */
	const MAX_ROWS = 50000;

	/**
	 * Constructor — wire up the admin-post handler.
	 */
	public function __construct() {
		add_action( 'admin_post_uatr_export_events', array( $this, 'handle_export' ) );
	}

	/**
	 * Entry point: validate the request, pick the format, stream the response.
	 */
	public function handle_export() {
		check_admin_referer( 'uatr_export_events' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export events.', 'unstoppable-activity-tracker' ), 403 );
		}

		if ( ! UATR_Installer::table_exists() ) {
			wp_die( esc_html__( 'Events table is missing.', 'unstoppable-activity-tracker' ), 503 );
		}

		// Format: csv or json. Whitelisted.
		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';
		if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
			$format = 'csv';
		}

		// Reuse the admin log filters so "Export" matches what the user sees.
		$f_object   = isset( $_POST['f_object'] ) ? sanitize_key( wp_unslash( $_POST['f_object'] ) ) : '';
		$f_severity = isset( $_POST['f_severity'] ) ? sanitize_key( wp_unslash( $_POST['f_severity'] ) ) : '';

		$allowed_objects    = array( '', 'user', 'post', 'plugin', 'theme', 'option', 'core', 'wc_order', 'wc_product', 'wc_coupon' );
		$allowed_severities = array( '', 'info', 'notice', 'warning', 'error', 'critical' );
		if ( ! in_array( $f_object, $allowed_objects, true ) ) {
			$f_object = '';
		}
		if ( ! in_array( $f_severity, $allowed_severities, true ) ) {
			$f_severity = '';
		}

		$rows = $this->fetch_rows( $f_object, $f_severity );

		$timestamp = gmdate( 'Y-m-d_His' );
		$filename  = 'activity-tracker-' . $timestamp . '.' . $format;

		if ( 'json' === $format ) {
			$this->stream_json( $rows, $filename );
		} else {
			$this->stream_csv( $rows, $filename );
		}
		exit;
	}

	/**
	 * Fetch filtered rows from the events table, capped at MAX_ROWS.
	 *
	 * @param string $f_object   Object filter or ''.
	 * @param string $f_severity Severity filter or ''.
	 * @return array[]
	 */
	private function fetch_rows( $f_object, $f_severity ) {
		global $wpdb;
		$table = UATR_Installer::get_table_name();

		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $f_object ) {
			$where[]  = 'object = %s';
			$params[] = $f_object;
		}
		if ( '' !== $f_severity ) {
			$where[]  = 'severity = %s';
			$params[] = $f_severity;
		}
		$params[]  = self::MAX_ROWS;
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT id, created_on, alert_id, severity, user_id, username, user_roles, object, event_type, ip, ua, message, post_title, post_type, meta FROM {$table} WHERE {$where_sql} ORDER BY created_on DESC LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Export query on custom table; identifier safe (WP prefix + hardcoded), filter and LIMIT values bound via prepare().
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Stream CSV directly to the browser via php://output.
	 *
	 * @param array[] $rows     Rows.
	 * @param string  $filename Download filename.
	 */
	private function stream_csv( $rows, $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming export to php://output; WP_Filesystem is not applicable.
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			return;
		}

		fputcsv( $out, array(
			'id',
			'timestamp_utc',
			'alert_id',
			'severity',
			'user_id',
			'username',
			'user_roles',
			'object',
			'event_type',
			'ip',
			'user_agent',
			'message',
			'post_title',
			'post_type',
			'meta',
		) );

		foreach ( $rows as $row ) {
			$ts = isset( $row['created_on'] ) ? (float) $row['created_on'] : 0;
			fputcsv( $out, array(
				(int) $row['id'],
				gmdate( 'Y-m-d H:i:s', (int) $ts ),
				(int) $row['alert_id'],
				(string) $row['severity'],
				(int) $row['user_id'],
				(string) $row['username'],
				(string) $row['user_roles'],
				(string) $row['object'],
				(string) $row['event_type'],
				(string) $row['ip'],
				(string) $row['ua'],
				(string) $row['message'],
				(string) $row['post_title'],
				(string) $row['post_type'],
				(string) $row['meta'], // Already JSON string in storage.
			) );
		}

		fclose( $out );
	}

	/**
	 * Stream JSON directly to the browser.
	 *
	 * @param array[] $rows     Rows.
	 * @param string  $filename Download filename.
	 */
	private function stream_json( $rows, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = array(
			'exported_at' => gmdate( 'c' ),
			'site_url'    => home_url(),
			'count'       => count( $rows ),
			'max_rows'    => self::MAX_ROWS,
			'events'      => array(),
		);

		foreach ( $rows as $row ) {
			$meta = null;
			if ( ! empty( $row['meta'] ) ) {
				$decoded = json_decode( (string) $row['meta'], true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$meta = $decoded;
				}
			}
			$out['events'][] = array(
				'id'         => (int) $row['id'],
				'timestamp'  => (float) $row['created_on'],
				'alert_id'   => (int) $row['alert_id'],
				'severity'   => (string) $row['severity'],
				'user_id'    => (int) $row['user_id'],
				'username'   => (string) $row['username'],
				'user_roles' => (string) $row['user_roles'],
				'object'     => (string) $row['object'],
				'event_type' => (string) $row['event_type'],
				'ip'         => (string) $row['ip'],
				'user_agent' => (string) $row['ua'],
				'message'    => (string) $row['message'],
				'post_title' => (string) $row['post_title'],
				'post_type'  => (string) $row['post_type'],
				'meta'       => $meta,
			);
		}

		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}
