<?php
/**
 * Admin flash notices.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays one-time flash notices after user actions (e.g. API key regeneration).
 *
 * By design this class only ever renders on the plugin's own admin pages.
 * There is no site-wide notice, no dashboard notice, no announcement banner.
 */
class UATR_Admin_Notices {

	/**
	 * Hook.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'display_flash_notices' ) );
	}

	/**
	 * One-time flash notices, shown only on this plugin's own admin pages.
	 */
	public function display_flash_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Scope to the plugin's own pages only — never fire on unrelated admin screens.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, UATR_PLUGIN_SLUG ) ) {
			return;
		}

		$notices = get_transient( UATR_OPTION_PREFIX . '_flash_notices' );
		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}

		$allowed_types = array( 'success', 'error', 'warning', 'info' );
		foreach ( $notices as $notice ) {
			$type    = isset( $notice['type'] ) ? (string) $notice['type'] : 'info';
			$message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
			if ( '' === $message ) {
				continue;
			}
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'info';
			}
			?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
				<p><?php echo wp_kses_post( $message ); ?></p>
			</div>
			<?php
		}

		delete_transient( UATR_OPTION_PREFIX . '_flash_notices' );
	}

	/**
	 * Queue a flash notice to display on the next pageload of one of our pages.
	 *
	 * @param string $message Text or limited HTML.
	 * @param string $type    success | error | warning | info.
	 */
	public static function add_flash_notice( $message, $type = 'success' ) {
		$notices = get_transient( UATR_OPTION_PREFIX . '_flash_notices' );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( UATR_OPTION_PREFIX . '_flash_notices', $notices, 60 );
	}
}
