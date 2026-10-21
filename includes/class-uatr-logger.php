<?php
/**
 * Event logger.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central write API for activity events.
 *
 * All event hook handlers call $logger->log(...) — keeping insertion logic
 * in one place ensures every row uses prepared statements and consistent fields.
 */
class UATR_Logger {

	/* ------------------------------------------------------------------
	 * Event code map (alert_id values)
	 *
	 * 1xxx: authentication / users
	 * 2xxx: posts / pages / content
	 * 3xxx: settings / options
	 * 4xxx: themes
	 * 5xxx: plugins
	 * 6xxx: core
	 * ---------------------------------------------------------------- */

	const EVENT_USER_LOGIN          = 1000;
	const EVENT_USER_LOGIN_FAILED   = 1001;
	const EVENT_USER_LOGOUT         = 1002;
	const EVENT_USER_REGISTERED     = 1003;
	const EVENT_USER_DELETED        = 1004;
	const EVENT_USER_UPDATED        = 1005;
	const EVENT_USER_ROLE_CHANGED   = 1006;

	const EVENT_POST_CREATED        = 2000;
	const EVENT_POST_UPDATED        = 2001;
	const EVENT_POST_PUBLISHED      = 2002;
	const EVENT_POST_TRASHED        = 2003;
	const EVENT_POST_RESTORED       = 2004;
	const EVENT_POST_DELETED        = 2005;
	const EVENT_POST_UNPUBLISHED    = 2006;

	const EVENT_OPTION_CHANGED      = 3000;

	const EVENT_THEME_SWITCHED      = 4000;
	const EVENT_THEME_INSTALLED     = 4001;
	const EVENT_THEME_UPDATED       = 4002;
	const EVENT_THEME_DELETED       = 4003;

	const EVENT_PLUGIN_ACTIVATED    = 5000;
	const EVENT_PLUGIN_DEACTIVATED  = 5001;
	const EVENT_PLUGIN_INSTALLED    = 5002;
	const EVENT_PLUGIN_UPDATED      = 5003;
	const EVENT_PLUGIN_DELETED      = 5004;

	const EVENT_CORE_UPDATED        = 6000;

	// WooCommerce events (7000 range) — only fire if WooCommerce is active.
	const EVENT_WC_ORDER_CREATED    = 7000;
	const EVENT_WC_ORDER_STATUS     = 7001;
	const EVENT_WC_ORDER_REFUNDED   = 7002;
	const EVENT_WC_ORDER_DELETED    = 7003;
	const EVENT_WC_PRODUCT_CREATED  = 7010;
	const EVENT_WC_PRODUCT_UPDATED  = 7011;
	const EVENT_WC_PRODUCT_DELETED  = 7012;
	const EVENT_WC_COUPON_CREATED   = 7020;
	const EVENT_WC_COUPON_USED      = 7021;

	/**
	 * Insert an event row.
	 *
	 * @param int    $alert_id   Numeric event code (see constants above).
	 * @param string $object     Object class: 'user', 'post', 'plugin', 'theme', 'option', 'core'.
	 * @param string $event_type Action: 'login', 'created', 'updated', 'deleted', 'activated', etc.
	 * @param string $message    Human-readable description.
	 * @param array  $context    Optional: severity, post_title, post_type, meta, user_id, username override.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function log( $alert_id, $object, $event_type, $message, $context = array() ) {
		global $wpdb;

		// Allow callers to override user attribution (e.g. before_delete_user).
		if ( isset( $context['user_id'] ) ) {
			$user_id   = (int) $context['user_id'];
			$username  = isset( $context['username'] ) ? (string) $context['username'] : '';
			$user_roles = isset( $context['user_roles'] ) ? (string) $context['user_roles'] : '';
			if ( '' === $username || '' === $user_roles ) {
				$user_obj = get_userdata( $user_id );
				if ( $user_obj ) {
					if ( '' === $username ) {
						$username = $user_obj->user_login;
					}
					if ( '' === $user_roles ) {
						$user_roles = is_array( $user_obj->roles ) ? implode( ',', $user_obj->roles ) : '';
					}
				}
			}
		} else {
			$current    = wp_get_current_user();
			$user_id    = $current && $current->ID ? (int) $current->ID : 0;
			$username   = $current && $current->ID ? (string) $current->user_login : '';
			$user_roles = ( $current && $current->ID && is_array( $current->roles ) )
				? implode( ',', $current->roles )
				: '';
		}

		$severity = isset( $context['severity'] ) ? (string) $context['severity'] : 'info';
		$allowed_severity = array( 'info', 'notice', 'warning', 'error', 'critical' );
		if ( ! in_array( $severity, $allowed_severity, true ) ) {
			$severity = 'info';
		}

		$post_title = isset( $context['post_title'] ) ? (string) $context['post_title'] : '';
		$post_type  = isset( $context['post_type'] ) ? (string) $context['post_type'] : '';
		$meta       = isset( $context['meta'] ) && is_array( $context['meta'] ) ? wp_json_encode( $context['meta'] ) : null;

		$data = array(
			'created_on' => microtime( true ),
			'alert_id'   => (int) $alert_id,
			'severity'   => $severity,
			'user_id'    => $user_id,
			'username'   => $this->truncate( $username, 60 ),
			'user_roles' => $this->truncate( $user_roles, 255 ),
			'object'     => $this->truncate( (string) $object, 50 ),
			'event_type' => $this->truncate( (string) $event_type, 50 ),
			'ip'         => $this->get_ip(),
			'ua'         => $this->get_ua(),
			'message'    => (string) $message,
			'post_title' => $this->truncate( $post_title, 255 ),
			'post_type'  => $this->truncate( $post_type, 50 ),
			'meta'       => $meta,
		);

		$formats = array(
			'%f', // created_on
			'%d', // alert_id
			'%s', // severity
			'%d', // user_id
			'%s', // username
			'%s', // user_roles
			'%s', // object
			'%s', // event_type
			'%s', // ip
			'%s', // ua
			'%s', // message
			'%s', // post_title
			'%s', // post_type
			'%s', // meta
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom activity table; caching not applicable.
		$result = $wpdb->insert( UATR_Installer::get_table_name(), $data, $formats );

		if ( false === $result ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[UATR] Failed to insert event: ' . $wpdb->last_error );
			}
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Capture the requesting IP.
	 *
	 * Uses REMOTE_ADDR only — X-Forwarded-For is not trusted because it
	 * can be spoofed unless we know we're behind a specific proxy.
	 *
	 * @return string
	 */
	private function get_ip() {
		// Sanitize and unslash inline in a single expression so static analysis can trace it.
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		return $this->truncate( $ip, 45 );
	}

	/**
	 * Capture and sanitize the user agent.
	 *
	 * @return string
	 */
	private function get_ua() {
		// Sanitize and unslash inline in a single expression so static analysis can trace it.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		return $this->truncate( $ua, 500 );
	}

	/**
	 * Safe substr that won't break on multibyte strings.
	 *
	 * @param string $str    Input.
	 * @param int    $length Max length.
	 * @return string
	 */
	private function truncate( $str, $length ) {
		$str = (string) $str;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $str, 0, $length );
		}
		return substr( $str, 0, $length );
	}
}
