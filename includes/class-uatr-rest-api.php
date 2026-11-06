<?php
/**
 * REST API endpoints.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the events table to authenticated callers.
 */
class UATR_REST_API {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes under the unstoppable/v1 namespace.
	 */
	public function register_routes() {
		register_rest_route(
			UATR_REST_NAMESPACE,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_ping' ),
				'permission_callback' => array( $this, 'check_api_key' ),
			)
		);

		register_rest_route(
			UATR_REST_NAMESPACE,
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_events' ),
				'permission_callback' => array( $this, 'check_api_key' ),
				'args'                => array(
					'limit'      => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_limit' ),
					),
					'since'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'before'     => array(
						// Added in 2.3.0 for downtime forensics: fetch events BEFORE a known timestamp
						// (e.g. an uptime monitor's downtime alert). Mirrors `since` but in the other
						// direction. Accepts a Unix timestamp or a MySQL datetime string.
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'object'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_object' ),
					),
					'event_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'severity'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_severity' ),
					),
				),
			)
		);

		register_rest_route(
			UATR_REST_NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_stats' ),
				'permission_callback' => array( $this, 'check_api_key' ),
			)
		);
	}

	/* --------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------ */

	/**
	 * Validate `limit`.
	 *
	 * @param mixed $value Value.
	 * @return bool|WP_Error
	 */
	public function validate_limit( $value ) {
		$value = (int) $value;
		if ( $value < 1 || $value > 200 ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'limit must be between 1 and 200.', 'unstoppable-activity-tracker' ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Validate `object` against the allowed whitelist.
	 *
	 * @param mixed $value Value.
	 * @return bool|WP_Error
	 */
	public function validate_object( $value ) {
		if ( '' === $value ) {
			return true;
		}
		$allowed = array( 'user', 'post', 'plugin', 'theme', 'option', 'core' );
		if ( ! in_array( (string) $value, $allowed, true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: comma-separated list */
					__( 'object must be one of: %s', 'unstoppable-activity-tracker' ),
					implode( ', ', $allowed )
				),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Validate `severity` against the allowed whitelist.
	 *
	 * @param mixed $value Value.
	 * @return bool|WP_Error
	 */
	public function validate_severity( $value ) {
		if ( '' === $value ) {
			return true;
		}
		$allowed = array( 'info', 'notice', 'warning', 'error', 'critical' );
		if ( ! in_array( (string) $value, $allowed, true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: comma-separated list */
					__( 'severity must be one of: %s', 'unstoppable-activity-tracker' ),
					implode( ', ', $allowed )
				),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Permission callback: verify API enabled and key matches in constant time.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_api_key( $request ) {
		if ( ! UATR_Plugin::is_api_enabled() ) {
			return new WP_Error(
				'uatr_api_disabled',
				__( 'API endpoint is disabled.', 'unstoppable-activity-tracker' ),
				array( 'status' => 503 )
			);
		}

		$stored_key   = UATR_Plugin::get_api_key();
		$provided_key = (string) $request->get_header( UATR_AUTH_HEADER );

		if ( '' === $stored_key || '' === $provided_key ) {
			return new WP_Error(
				'uatr_unauthorized',
				__( 'Missing API key.', 'unstoppable-activity-tracker' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( $stored_key, $provided_key ) ) {
			return new WP_Error(
				'uatr_unauthorized',
				__( 'Invalid API key.', 'unstoppable-activity-tracker' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/* --------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------ */

	/**
	 * Health check.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_ping() {
		return rest_ensure_response(
			array(
				'status'        => 'ok',
				'plugin'        => UATR_PLUGIN_SLUG,
				'version'       => UATR_VERSION,
				'site'          => get_bloginfo( 'name' ),
				'url'           => home_url(),
				'wp_version'    => get_bloginfo( 'version' ),
				'php_version'   => PHP_VERSION,
				'table_exists'  => UATR_Installer::table_exists(),
				'event_count'   => UATR_Installer::table_exists() ? UATR_Installer::count_events() : 0,
				'time'          => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Return events with filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_events( $request ) {
		global $wpdb;

		if ( ! UATR_Installer::table_exists() ) {
			return new WP_Error(
				'uatr_no_table',
				__( 'Events table is missing. Try deactivating and reactivating the plugin.', 'unstoppable-activity-tracker' ),
				array( 'status' => 503 )
			);
		}

		$limit = (int) $request->get_param( 'limit' );
		if ( $limit < 1 ) {
			$limit = 50;
		}
		$limit = min( $limit, 200 );

		$where  = array( '1=1' );
		$params = array();

		// since (Unix timestamp or MySQL datetime).
		$since = $request->get_param( 'since' );
		if ( ! empty( $since ) ) {
			$ts = is_numeric( $since ) ? (int) $since : strtotime( (string) $since );
			if ( $ts && $ts > 0 ) {
				$where[]  = 'created_on > %f';
				$params[] = (float) $ts;
			}
		}

		// before (Unix timestamp or MySQL datetime) — added in 2.3.0 for downtime forensics.
		// Combined with `since`, lets consumers request a precise window: ?since=X&before=Y.
		$before = $request->get_param( 'before' );
		if ( ! empty( $before ) ) {
			$ts = is_numeric( $before ) ? (int) $before : strtotime( (string) $before );
			if ( $ts && $ts > 0 ) {
				$where[]  = 'created_on < %f';
				$params[] = (float) $ts;
			}
		}

		// object filter.
		$object = $request->get_param( 'object' );
		if ( ! empty( $object ) ) {
			$where[]  = 'object = %s';
			$params[] = (string) $object;
		}

		// event_type filter.
		$event_type = $request->get_param( 'event_type' );
		if ( ! empty( $event_type ) ) {
			$where[]  = 'event_type = %s';
			$params[] = (string) $event_type;
		}

		// severity filter.
		$severity = $request->get_param( 'severity' );
		if ( ! empty( $severity ) ) {
			$where[]  = 'severity = %s';
			$params[] = (string) $severity;
		}

		$params[] = $limit;

		$table     = UATR_Installer::get_table_name();
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT id, created_on, alert_id, severity, user_id, username, user_roles,
				object, event_type, ip, ua, message, post_title, post_type, meta
				FROM {$table}
				WHERE {$where_sql}
				ORDER BY created_on DESC
				LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql interpolates only $table (WP prefix + hardcoded) and $where_sql (built from whitelisted column names); values pass through prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( empty( $rows ) ) {
			return rest_ensure_response(
				array(
					'events' => array(),
					'count'  => 0,
				)
			);
		}

		$events = array();
		foreach ( $rows as $row ) {
			$meta = null;
			if ( ! empty( $row['meta'] ) ) {
				$decoded = json_decode( (string) $row['meta'], true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$meta = $decoded;
				}
			}

			$events[] = array(
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

		return rest_ensure_response(
			array(
				'events' => $events,
				'count'  => count( $events ),
			)
		);
	}

	/**
	 * Aggregate counts — useful for the portal dashboard.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_stats( $request ) {
		global $wpdb;

		if ( ! UATR_Installer::table_exists() ) {
			return new WP_Error(
				'uatr_no_table',
				__( 'Events table is missing.', 'unstoppable-activity-tracker' ),
				array( 'status' => 503 )
			);
		}

		$table = UATR_Installer::get_table_name();
		$now   = microtime( true );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate count; identifier is safe.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate count; identifier is safe, value bound via prepare().
		$last_24h = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_on > %f", $now - DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate count; identifier is safe, value bound via prepare().
		$last_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_on > %f", $now - ( 7 * DAY_IN_SECONDS ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate GROUP BY on custom table; identifier is safe.
		$by_object = $wpdb->get_results( "SELECT object, COUNT(*) AS n FROM {$table} GROUP BY object ORDER BY n DESC", ARRAY_A );

		$counts_by_object = array();
		foreach ( (array) $by_object as $row ) {
			$counts_by_object[ (string) $row['object'] ] = (int) $row['n'];
		}

		return rest_ensure_response(
			array(
				'total'           => $total,
				'last_24h'        => $last_24h,
				'last_7d'         => $last_7d,
				'by_object'       => $counts_by_object,
				'retention_days'  => (int) get_option( UATR_OPTION_PREFIX . '_retention_days', 90 ),
				'oldest_kept_at'  => UATR_Plugin::get_oldest_event_time(),
			)
		);
	}
}
