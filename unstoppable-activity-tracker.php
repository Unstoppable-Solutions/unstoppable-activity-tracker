<?php
/**
 * Plugin Name:       Unstoppable Activity Tracker
 * Plugin URI:        https://unstoppablesolutions.app/plugins/unstoppable-activity-tracker
 * Description:       Standalone activity tracker for WordPress. Visual Insights dashboard, filterable Activity Log, CSV/JSON export, optional email digests, optional WooCommerce event tracking, and a secure REST API for external sync.
 * Version:           2.3.0
 * Author:            Unstoppable Solutions
 * Author URI:        https://unstoppablesolutions.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       unstoppable-activity-tracker
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 *
 * @package Unstoppable_Activity_Tracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'UATR_VERSION', '2.3.0' );
define( 'UATR_DB_VERSION', '1.0.0' );
define( 'UATR_PLUGIN_FILE', __FILE__ );
define( 'UATR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UATR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UATR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'UATR_PLUGIN_SLUG', 'unstoppable-activity-tracker' );
define( 'UATR_OPTION_PREFIX', 'uatr' );
define( 'UATR_TABLE_SUFFIX', 'uatr_events' );
define( 'UATR_REST_NAMESPACE', 'unstoppable/v1' );
define( 'UATR_AUTH_HEADER', 'X-Unstoppable-Key' );
define( 'UATR_CRON_HOOK', 'uatr_daily_cleanup' );

// Includes.
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-installer.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-logger.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-events.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-rest-api.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-admin-notices.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-insights.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-wc-events.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-exporter.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-digest.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-admin.php';
require_once UATR_PLUGIN_DIR . 'includes/class-uatr-plugin.php';

// Activation / deactivation hooks.
register_activation_hook( __FILE__, array( 'UATR_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UATR_Plugin', 'deactivate' ) );

// Bootstrap.
UATR_Plugin::get_instance();
