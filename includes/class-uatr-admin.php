<?php
/**
 * Admin pages and menus.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin UI.
 */
class UATR_Admin {

	/**
	 * Notices reference for flash messages.
	 *
	 * @var UATR_Admin_Notices
	 */
	private $notices;

	/**
	 * Constructor.
	 *
	 * @param UATR_Admin_Notices $notices Notices.
	 */
	public function __construct( $notices ) {
		$this->notices = $notices;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Form handler — regenerate key.
		add_action( 'admin_post_uatr_regenerate_key', array( $this, 'handle_regenerate_key' ) );

		// Plugins-list row action (Settings link only — no update mechanism on WP.org).
		add_filter( 'plugin_action_links_' . UATR_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	/* ================================================================
	 * Menus
	 * ================================================================ */

	/**
	 * Register the top-level menu and submenus.
	 *
	 * Menu structure (as of 2.1.0):
	 *   Activity Tracker (top-level)  → Insights page (default landing)
	 *   ├── Insights                  → same as top-level
	 *   ├── Activity Log              → raw event viewer
	 *   └── Settings                  → API key + retention config
	 *
	 * NOTE: The Settings page URL changed in 2.1.0 from
	 * ?page=unstoppable-activity-tracker to ?page=unstoppable-activity-tracker-settings
	 * because the top-level slug now points at Insights.
	 */
	public function register_menu() {
		$insights = UATR_Plugin::get_instance()->insights;

		add_menu_page(
			__( 'Unstoppable Activity Tracker', 'unstoppable-activity-tracker' ),
			__( 'Activity Tracker', 'unstoppable-activity-tracker' ),
			'manage_options',
			UATR_PLUGIN_SLUG,
			array( $insights, 'render_page' ),
			'dashicons-shield',
			80
		);

		// Insights submenu shares the parent slug — WordPress renders this as the "current" default.
		add_submenu_page(
			UATR_PLUGIN_SLUG,
			__( 'Insights', 'unstoppable-activity-tracker' ),
			__( 'Insights', 'unstoppable-activity-tracker' ),
			'manage_options',
			UATR_PLUGIN_SLUG,
			array( $insights, 'render_page' )
		);

		add_submenu_page(
			UATR_PLUGIN_SLUG,
			__( 'Activity Log', 'unstoppable-activity-tracker' ),
			__( 'Activity Log', 'unstoppable-activity-tracker' ),
			'manage_options',
			UATR_PLUGIN_SLUG . '-log',
			array( $this, 'render_log_page' )
		);

		add_submenu_page(
			UATR_PLUGIN_SLUG,
			__( 'Settings', 'unstoppable-activity-tracker' ),
			__( 'Settings', 'unstoppable-activity-tracker' ),
			'manage_options',
			UATR_PLUGIN_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Build the gold menu branding CSS string.
	 *
	 * Returned as a string and attached via wp_add_inline_style() in enqueue_assets()
	 * — never echoed as a raw <style> block, per WordPress.org plugin guidelines.
	 *
	 * @return string
	 */
	private function get_menu_branding_css() {
		$slug = UATR_PLUGIN_SLUG;
		return "
			#adminmenu .toplevel_page_{$slug} .wp-menu-name { color: #f0b849 !important; }
			#adminmenu .toplevel_page_{$slug}:hover .wp-menu-name,
			#adminmenu .toplevel_page_{$slug}.current .wp-menu-name,
			#adminmenu .toplevel_page_{$slug}.wp-has-current-submenu .wp-menu-name { color: #fff !important; }
			#adminmenu .toplevel_page_{$slug} .wp-menu-image:before { color: #f0b849; }
			#adminmenu .toplevel_page_{$slug}:hover .wp-menu-image:before,
			#adminmenu .toplevel_page_{$slug}.current .wp-menu-image:before,
			#adminmenu .toplevel_page_{$slug}.wp-has-current-submenu .wp-menu-image:before { color: #fff; }
		";
	}

	/* ================================================================
	 * Settings registration
	 * ================================================================ */

	/**
	 * Register options via the Settings API for proper sanitization.
	 */
	public function register_settings() {
		register_setting(
			'uatr_settings',
			UATR_OPTION_PREFIX . '_api_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_enabled' ),
				'default'           => '1',
			)
		);

		register_setting(
			'uatr_settings',
			UATR_OPTION_PREFIX . '_retention_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_retention' ),
				'default'           => 90,
			)
		);

		// Digest settings.
		register_setting(
			'uatr_settings',
			'uatr_digest_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_enabled' ),
				'default'           => '0',
			)
		);

		register_setting(
			'uatr_settings',
			'uatr_digest_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_email' ),
				'default'           => '',
			)
		);

		register_setting(
			'uatr_settings',
			'uatr_digest_frequency',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_frequency' ),
				'default'           => 'daily',
			)
		);

		register_setting(
			'uatr_settings',
			'uatr_digest_threshold',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_threshold' ),
				'default'           => 'warning',
			)
		);
	}

	/**
	 * Sanitize digest recipient email — require a valid address or fall back to blank
	 * so UATR_Digest::get_recipient() uses the admin email.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_digest_email( $value ) {
		$value = sanitize_email( (string) $value );
		return is_email( $value ) ? $value : '';
	}

	/**
	 * Sanitize digest frequency — whitelist.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_digest_frequency( $value ) {
		$value = (string) $value;
		return ( 'weekly' === $value ) ? 'weekly' : 'daily';
	}

	/**
	 * Sanitize digest threshold — whitelist.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_digest_threshold( $value ) {
		$value = (string) $value;
		if ( ! in_array( $value, array( 'warning', 'error', 'critical' ), true ) ) {
			$value = 'warning';
		}
		return $value;
	}

	/**
	 * Sanitize the enabled checkbox.
	 *
	 * @param mixed $value Raw input.
	 * @return string '1' or '0'.
	 */
	public function sanitize_enabled( $value ) {
		return ( '1' === (string) $value ) ? '1' : '0';
	}

	/**
	 * Sanitize retention days.
	 *
	 * @param mixed $value Raw input.
	 * @return int 0 (forever) or 1-3650.
	 */
	public function sanitize_retention( $value ) {
		$value = (int) $value;
		if ( $value < 0 ) {
			$value = 0;
		}
		if ( $value > 3650 ) {
			$value = 3650;
		}
		return $value;
	}

	/* ================================================================
	 * Asset enqueue
	 * ================================================================ */

	/**
	 * Enqueue admin assets.
	 *
	 * Two layers:
	 *  1. The menu-branding handle (empty file) is registered on every admin page
	 *     so that wp_add_inline_style can attach the gold accent. This is the
	 *     canonical WordPress.org-compliant replacement for an `admin_head` style block.
	 *  2. The full admin CSS/JS is only enqueued on our settings + log pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// 1. Menu branding — every admin page. The handle has no real file behind it;
		//    we use it purely as an anchor for wp_add_inline_style.
		wp_register_style( 'uatr-menu-branding', false, array(), UATR_VERSION );
		wp_enqueue_style( 'uatr-menu-branding' );
		wp_add_inline_style( 'uatr-menu-branding', $this->get_menu_branding_css() );

		// 2. Heavier assets — only on our pages.
		$is_our_page = (
			'toplevel_page_' . UATR_PLUGIN_SLUG === $hook
			|| false !== strpos( (string) $hook, UATR_PLUGIN_SLUG )
		);
		if ( ! $is_our_page ) {
			return;
		}

		wp_enqueue_style(
			'uatr-admin',
			UATR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			UATR_VERSION
		);

		// 3. Insights-page-only assets: Chart.js + insights.js/css.
		//    The top-level page hook is 'toplevel_page_{slug}' which is Insights (the default).
		$is_insights_page = ( 'toplevel_page_' . UATR_PLUGIN_SLUG === $hook );
		if ( $is_insights_page ) {
			wp_enqueue_style(
				'uatr-insights',
				UATR_PLUGIN_URL . 'assets/css/insights.css',
				array( 'uatr-admin' ),
				UATR_VERSION
			);

			wp_enqueue_script(
				'uatr-chartjs',
				UATR_PLUGIN_URL . 'assets/js/chart.min.js',
				array(),
				'4.5.0',
				true
			);

			wp_enqueue_script(
				'uatr-insights',
				UATR_PLUGIN_URL . 'assets/js/insights.js',
				array( 'jquery', 'uatr-chartjs' ),
				UATR_VERSION,
				true
			);

			wp_localize_script(
				'uatr-insights',
				'uatr_insights',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'uatr_insights' ),
					'i18n'     => array(
						'loading'              => __( 'Loading…', 'unstoppable-activity-tracker' ),
						'error'                => __( 'Could not load insights.', 'unstoppable-activity-tracker' ),
						'events'               => __( 'Events', 'unstoppable-activity-tracker' ),
						'events_short'         => __( 'events', 'unstoppable-activity-tracker' ),
						'last_active'          => __( 'Last active', 'unstoppable-activity-tracker' ),
						'no_users'             => __( 'No user activity in this range.', 'unstoppable-activity-tracker' ),
						'just_now'             => __( 'just now', 'unstoppable-activity-tracker' ),
						'min_ago'              => __( 'min ago', 'unstoppable-activity-tracker' ),
						'hour_ago'             => __( 'hours ago', 'unstoppable-activity-tracker' ),
						'day_ago'              => __( 'days ago', 'unstoppable-activity-tracker' ),
						'week_ago'             => __( 'weeks ago', 'unstoppable-activity-tracker' ),
						'failed_logins'        => __( 'Failed logins', 'unstoppable-activity-tracker' ),
						'new_users'            => __( 'New users created', 'unstoppable-activity-tracker' ),
						'deleted_users'        => __( 'Users deleted', 'unstoppable-activity-tracker' ),
						'role_changes'         => __( 'Role changes', 'unstoppable-activity-tracker' ),
						'plugin_installs'      => __( 'Plugins installed', 'unstoppable-activity-tracker' ),
						'plugin_activations'   => __( 'Plugins activated', 'unstoppable-activity-tracker' ),
						'plugin_deactivations' => __( 'Plugins deactivated', 'unstoppable-activity-tracker' ),
						'core_updates'         => __( 'Core updates', 'unstoppable-activity-tracker' ),
					),
				)
			);
		}
	}

	/* ================================================================
	 * Form / AJAX handlers
	 * ================================================================ */

	/**
	 * Regenerate the API key.
	 */
	public function handle_regenerate_key() {
		check_admin_referer( 'uatr_regenerate_key' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'unstoppable-activity-tracker' ), 403 );
		}

		update_option( UATR_OPTION_PREFIX . '_api_key', UATR_Plugin::generate_api_key() );

		UATR_Admin_Notices::add_flash_notice(
			__( 'API key regenerated. Update it wherever you use it — the old key no longer works.', 'unstoppable-activity-tracker' ),
			'success'
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . UATR_PLUGIN_SLUG . '-settings' ) );
		exit;
	}

	/**
	 * Add a Settings link to the plugins-list row action.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . UATR_PLUGIN_SLUG . '-settings' ) ),
			esc_html__( 'Settings', 'unstoppable-activity-tracker' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/* ================================================================
	 * Page renderers
	 * ================================================================ */

	/**
	 * Settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-activity-tracker' ) );
		}

		$api_key        = UATR_Plugin::get_api_key();
		$enabled        = UATR_Plugin::is_api_enabled();
		$retention_days = (int) get_option( UATR_OPTION_PREFIX . '_retention_days', 90 );
		$endpoint_url   = rest_url( UATR_REST_NAMESPACE . '/events' );
		$ping_url       = rest_url( UATR_REST_NAMESPACE . '/ping' );
		$event_count    = UATR_Installer::table_exists() ? UATR_Installer::count_events() : 0;
		?>
		<div class="wrap uatr-wrap">
			<h1><?php esc_html_e( 'Unstoppable Activity Tracker', 'unstoppable-activity-tracker' ); ?></h1>
			<p class="description uatr-intro">
				<?php esc_html_e( 'This plugin works out of the box. Everything below is optional — the defaults are safe for most sites.', 'unstoppable-activity-tracker' ); ?>
			</p>

			<div class="uatr-stats-bar">
				<span class="uatr-stat">
					<strong><?php echo esc_html( number_format_i18n( $event_count ) ); ?></strong>
					<?php esc_html_e( 'events recorded', 'unstoppable-activity-tracker' ); ?>
				</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . UATR_PLUGIN_SLUG ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'View Insights', 'unstoppable-activity-tracker' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . UATR_PLUGIN_SLUG . '-log' ) ); ?>" class="button">
					<?php esc_html_e( 'View Activity Log', 'unstoppable-activity-tracker' ); ?>
				</a>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'uatr_settings' ); ?>
				<h2><?php esc_html_e( 'Settings', 'unstoppable-activity-tracker' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'API Endpoint', 'unstoppable-activity-tracker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( UATR_OPTION_PREFIX . '_api_enabled' ); ?>" value="1" <?php checked( $enabled ); ?>>
								<?php esc_html_e( 'Enabled', 'unstoppable-activity-tracker' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Uncheck to temporarily block all REST requests without rotating the key. Event tracking continues either way.', 'unstoppable-activity-tracker' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uatr-retention"><?php esc_html_e( 'Retention (days)', 'unstoppable-activity-tracker' ); ?></label></th>
						<td>
							<input type="number" id="uatr-retention"
								name="<?php echo esc_attr( UATR_OPTION_PREFIX . '_retention_days' ); ?>"
								value="<?php echo esc_attr( $retention_days ); ?>"
								min="0" max="3650" class="small-text">
							<p class="description">
								<?php esc_html_e( 'Events older than this are deleted by a daily cleanup job. Use 0 to keep events forever.', 'unstoppable-activity-tracker' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 style="margin-top:30px;"><?php esc_html_e( 'Email Digest', 'unstoppable-activity-tracker' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Receive a scheduled email summary of important events. Opt-in — nothing is sent unless you enable it below.', 'unstoppable-activity-tracker' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable digest', 'unstoppable-activity-tracker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="uatr_digest_enabled" value="1" <?php checked( UATR_Digest::is_enabled() ); ?>>
								<?php esc_html_e( 'Send me a periodic email summary of events at or above the chosen severity.', 'unstoppable-activity-tracker' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uatr-digest-email"><?php esc_html_e( 'Recipient', 'unstoppable-activity-tracker' ); ?></label></th>
						<td>
							<input type="email" id="uatr-digest-email"
								name="uatr_digest_email"
								value="<?php echo esc_attr( get_option( 'uatr_digest_email', '' ) ); ?>"
								placeholder="<?php echo esc_attr( get_option( 'admin_email', '' ) ); ?>"
								class="regular-text">
							<p class="description">
								<?php esc_html_e( 'Leave blank to send to the site admin email.', 'unstoppable-activity-tracker' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uatr-digest-frequency"><?php esc_html_e( 'Frequency', 'unstoppable-activity-tracker' ); ?></label></th>
						<td>
							<?php $freq = UATR_Digest::get_frequency(); ?>
							<select id="uatr-digest-frequency" name="uatr_digest_frequency">
								<option value="daily" <?php selected( $freq, 'daily' ); ?>><?php esc_html_e( 'Daily (last 24 hours)', 'unstoppable-activity-tracker' ); ?></option>
								<option value="weekly" <?php selected( $freq, 'weekly' ); ?>><?php esc_html_e( 'Weekly (last 7 days)', 'unstoppable-activity-tracker' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uatr-digest-threshold"><?php esc_html_e( 'Minimum severity', 'unstoppable-activity-tracker' ); ?></label></th>
						<td>
							<?php $thr = UATR_Digest::get_threshold(); ?>
							<select id="uatr-digest-threshold" name="uatr_digest_threshold">
								<option value="warning" <?php selected( $thr, 'warning' ); ?>><?php esc_html_e( 'Warning or above (failed logins, deletes, refunds)', 'unstoppable-activity-tracker' ); ?></option>
								<option value="error" <?php selected( $thr, 'error' ); ?>><?php esc_html_e( 'Error or above', 'unstoppable-activity-tracker' ); ?></option>
								<option value="critical" <?php selected( $thr, 'critical' ); ?>><?php esc_html_e( 'Critical only', 'unstoppable-activity-tracker' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Events below this severity are excluded from the digest. If no matching events occurred in the window, no email is sent.', 'unstoppable-activity-tracker' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<details class="uatr-external-integration">
				<summary>
					<span class="uatr-ext-label"><?php esc_html_e( 'External integration (optional)', 'unstoppable-activity-tracker' ); ?></span>
					<span class="uatr-ext-sub"><?php esc_html_e( 'Only needed if you want another tool — a dashboard, monitoring service, or custom script — to read your activity events via REST. Most users can ignore this.', 'unstoppable-activity-tracker' ); ?></span>
				</summary>

				<h2><?php esc_html_e( 'Connection Details', 'unstoppable-activity-tracker' ); ?></h2>
				<p><?php esc_html_e( 'Use these values to authenticate requests from your portal, dashboard, or any other consumer of the activity feed.', 'unstoppable-activity-tracker' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="uatr-endpoint"><?php esc_html_e( 'Endpoint URL', 'unstoppable-activity-tracker' ); ?></label></th>
						<td>
							<input type="text" id="uatr-endpoint"
								value="<?php echo esc_attr( $endpoint_url ); ?>"
								readonly class="large-text code" onclick="this.select();">
							<p class="description">
								<?php esc_html_e( 'The REST endpoint that returns activity events. Use this as the base URL in any consumer that needs to read events.', 'unstoppable-activity-tracker' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uatr-key"><?php esc_html_e( 'API Key', 'unstoppable-activity-tracker' ); ?></label></th>
						<td>
							<input type="text" id="uatr-key"
								value="<?php echo esc_attr( $api_key ); ?>"
								readonly class="large-text code" onclick="this.select();">
							<p class="description">
								<?php
								printf(
									/* translators: %s: the required HTTP header name */
									esc_html__( 'Send this in the %s header on every request. Treat it like a password.', 'unstoppable-activity-tracker' ),
									'<code>' . esc_html( UATR_AUTH_HEADER ) . '</code>'
								);
								?>
							</p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uatr-regenerate-form" onsubmit="return confirm('<?php echo esc_js( __( 'Generate a new API key? Any existing consumer using the current key will stop working until updated.', 'unstoppable-activity-tracker' ) ); ?>');">
								<?php wp_nonce_field( 'uatr_regenerate_key' ); ?>
								<input type="hidden" name="action" value="uatr_regenerate_key">
								<button type="submit" class="button"><?php esc_html_e( 'Generate a new key', 'unstoppable-activity-tracker' ); ?></button>
								<span class="description uatr-regenerate-hint">
									<?php esc_html_e( "Only needed if the current key was shared somewhere unsafe. Most users never do this.", 'unstoppable-activity-tracker' ); ?>
								</span>
							</form>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test from terminal', 'unstoppable-activity-tracker' ); ?></th>
						<td>
							<textarea readonly class="large-text code" rows="3" onclick="this.select();">curl -H "<?php echo esc_textarea( UATR_AUTH_HEADER ); ?>: <?php echo esc_textarea( $api_key ); ?>" "<?php echo esc_url( $ping_url ); ?>"</textarea>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Available endpoints', 'unstoppable-activity-tracker' ); ?></h2>
				<table class="widefat striped uatr-endpoints">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Method', 'unstoppable-activity-tracker' ); ?></th>
							<th><?php esc_html_e( 'URL', 'unstoppable-activity-tracker' ); ?></th>
							<th><?php esc_html_e( 'Purpose', 'unstoppable-activity-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>GET</td>
							<td><code><?php echo esc_html( $ping_url ); ?></code></td>
							<td><?php esc_html_e( 'Health check. Returns plugin status and event count.', 'unstoppable-activity-tracker' ); ?></td>
						</tr>
						<tr>
							<td>GET</td>
							<td><code><?php echo esc_html( $endpoint_url ); ?>?limit=50</code></td>
							<td><?php esc_html_e( 'Recent events (max 200).', 'unstoppable-activity-tracker' ); ?></td>
						</tr>
						<tr>
							<td>GET</td>
							<td><code><?php echo esc_html( $endpoint_url ); ?>?since=1714000000</code></td>
							<td><?php esc_html_e( 'Only events newer than the given Unix timestamp — for incremental sync.', 'unstoppable-activity-tracker' ); ?></td>
						</tr>
						<tr>
							<td>GET</td>
							<td><code><?php echo esc_html( $endpoint_url ); ?>?before=1714000000</code></td>
							<td><?php esc_html_e( 'Only events older than the given Unix timestamp — for post-outage forensics (combine with since for a window).', 'unstoppable-activity-tracker' ); ?></td>
						</tr>
						<tr>
							<td>GET</td>
							<td><code><?php echo esc_html( $endpoint_url ); ?>?object=user</code></td>
							<td><?php esc_html_e( 'Filter by object: user, post, plugin, theme, option, core, wc_order, wc_product, wc_coupon.', 'unstoppable-activity-tracker' ); ?></td>
						</tr>
						<tr>
							<td>GET</td>
							<td><code><?php echo esc_html( rest_url( UATR_REST_NAMESPACE . '/stats' ) ); ?></code></td>
							<td><?php esc_html_e( 'Aggregate counts (total, last 24h, last 7d, by object).', 'unstoppable-activity-tracker' ); ?></td>
						</tr>
					</tbody>
				</table>
				<p>
					<em>
						<?php
						printf(
							/* translators: %s: header */
							esc_html__( 'All requests require the header %s with your API key.', 'unstoppable-activity-tracker' ),
							'<code>' . esc_html( UATR_AUTH_HEADER ) . ': &lt;your key&gt;</code>'
						);
						?>
					</em>
				</p>
			</details>

			<p class="uatr-footer">
				<?php
				printf(
					/* translators: 1: plugin name, 2: version */
					esc_html__( '%1$s v%2$s — Unstoppable Solutions', 'unstoppable-activity-tracker' ),
					'<strong>Unstoppable Activity Tracker</strong>',
					esc_html( UATR_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Activity log viewer page.
	 */
	public function render_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-activity-tracker' ) );
		}

		global $wpdb;
		$table = UATR_Installer::get_table_name();

		// Sanitize filter params. These are read-only display filters — no nonce needed,
		// but Plugin Check flags unverified $_GET access, so silence those warnings explicitly.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page    = 30;
		$offset      = ( $paged - 1 ) * $per_page;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_object    = isset( $_GET['f_object'] ) ? sanitize_key( wp_unslash( $_GET['f_object'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_severity  = isset( $_GET['f_severity'] ) ? sanitize_key( wp_unslash( $_GET['f_severity'] ) ) : '';
		// Preset filters — one-click shortcut into a pre-built WHERE clause. Added in 2.3.0.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_preset    = isset( $_GET['f_preset'] ) ? sanitize_key( wp_unslash( $_GET['f_preset'] ) ) : '';

		$allowed_objects   = array( '', 'user', 'post', 'plugin', 'theme', 'option', 'core', 'wc_order', 'wc_product', 'wc_coupon' );
		$allowed_severities = array( '', 'info', 'notice', 'warning', 'error', 'critical' );
		$allowed_presets    = array( '', 'stability' );
		if ( ! in_array( $f_object, $allowed_objects, true ) ) {
			$f_object = '';
		}
		if ( ! in_array( $f_severity, $allowed_severities, true ) ) {
			$f_severity = '';
		}
		if ( ! in_array( $f_preset, $allowed_presets, true ) ) {
			$f_preset = '';
		}

		$where  = array( '1=1' );
		$params = array();

		if ( 'stability' === $f_preset ) {
			// "Site stability" preset — events that have historically caused downtime:
			// plugin/theme/core changes and critical option changes (siteurl/home swaps etc.).
			// These are the first events to review after a site went down and came back up.
			// Preset takes precedence: f_object / f_severity fields are ignored when set.
			$where[]  = 'object IN (%s, %s, %s)';
			$params[] = 'plugin';
			$params[] = 'theme';
			$params[] = 'core';
			$f_object = '';
			$f_severity = '';
		} else {
			if ( '' !== $f_object ) {
				$where[]  = 'object = %s';
				$params[] = $f_object;
			}
			if ( '' !== $f_severity ) {
				$where[]  = 'severity = %s';
				$params[] = $f_severity;
			}
		}
		$where_sql = implode( ' AND ', $where );

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $count_sql interpolates only $table (safe) and $where_sql (whitelisted columns); filter values bound via prepare().
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Unfiltered count on custom table; identifier is safe.
			$total = (int) $wpdb->get_var( $count_sql );
		}

		// Page query.
		$page_params   = array_merge( $params, array( $per_page, $offset ) );
		$page_sql      = "SELECT id, created_on, alert_id, severity, user_id, username, object, event_type, ip, message
				FROM {$table}
				WHERE {$where_sql}
				ORDER BY created_on DESC
				LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $page_sql interpolates only $table (safe) and $where_sql (whitelisted columns); filter + pagination values bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $page_sql, $page_params ), ARRAY_A );

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$base_url    = admin_url( 'admin.php?page=' . UATR_PLUGIN_SLUG . '-log' );

		?>
		<div class="wrap uatr-wrap">
			<h1><?php esc_html_e( 'Activity Log', 'unstoppable-activity-tracker' ); ?></h1>

			<div class="uatr-log-presets">
				<span class="uatr-preset-label"><?php esc_html_e( 'Quick filters:', 'unstoppable-activity-tracker' ); ?></span>
				<a href="<?php echo esc_url( add_query_arg( 'f_preset', 'stability', $base_url ) ); ?>"
				   class="button <?php echo 'stability' === $f_preset ? 'button-primary' : ''; ?>"
				   title="<?php esc_attr_e( 'Show only plugin, theme, and core changes — the usual suspects when a site goes down.', 'unstoppable-activity-tracker' ); ?>">
					<?php esc_html_e( 'Site-stability events', 'unstoppable-activity-tracker' ); ?>
				</a>
				<?php if ( 'stability' === $f_preset ) : ?>
					<span class="uatr-preset-note">
						<?php esc_html_e( 'Showing plugin, theme, and core changes. Object / severity dropdowns are disabled while this preset is active.', 'unstoppable-activity-tracker' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<form method="get" class="uatr-log-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( UATR_PLUGIN_SLUG . '-log' ); ?>">
				<select name="f_object" <?php disabled( 'stability', $f_preset ); ?>>
					<option value=""><?php esc_html_e( 'All objects', 'unstoppable-activity-tracker' ); ?></option>
					<?php
					$object_labels = array(
						'user'       => __( 'User', 'unstoppable-activity-tracker' ),
						'post'       => __( 'Post', 'unstoppable-activity-tracker' ),
						'plugin'     => __( 'Plugin', 'unstoppable-activity-tracker' ),
						'theme'      => __( 'Theme', 'unstoppable-activity-tracker' ),
						'option'     => __( 'Option', 'unstoppable-activity-tracker' ),
						'core'       => __( 'Core', 'unstoppable-activity-tracker' ),
						'wc_order'   => __( 'WC Order', 'unstoppable-activity-tracker' ),
						'wc_product' => __( 'WC Product', 'unstoppable-activity-tracker' ),
						'wc_coupon'  => __( 'WC Coupon', 'unstoppable-activity-tracker' ),
					);
					foreach ( $object_labels as $opt => $label ) :
					?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $f_object, $opt ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="f_severity" <?php disabled( 'stability', $f_preset ); ?>>
					<option value=""><?php esc_html_e( 'All severities', 'unstoppable-activity-tracker' ); ?></option>
					<?php foreach ( array( 'info', 'notice', 'warning', 'error', 'critical' ) as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $f_severity, $opt ); ?>><?php echo esc_html( ucfirst( $opt ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'unstoppable-activity-tracker' ); ?></button>
				<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'unstoppable-activity-tracker' ); ?></a>
				<span class="uatr-log-total">
					<?php
					printf(
						/* translators: %s: number of events */
						esc_html__( '%s events match', 'unstoppable-activity-tracker' ),
						'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
					);
					?>
				</span>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uatr-export-form">
				<?php wp_nonce_field( 'uatr_export_events' ); ?>
				<input type="hidden" name="action" value="uatr_export_events">
				<input type="hidden" name="f_object" value="<?php echo esc_attr( $f_object ); ?>">
				<input type="hidden" name="f_severity" value="<?php echo esc_attr( $f_severity ); ?>">
				<label><?php esc_html_e( 'Export matching events as:', 'unstoppable-activity-tracker' ); ?></label>
				<button type="submit" name="format" value="csv" class="button"><?php esc_html_e( 'CSV', 'unstoppable-activity-tracker' ); ?></button>
				<button type="submit" name="format" value="json" class="button"><?php esc_html_e( 'JSON', 'unstoppable-activity-tracker' ); ?></button>
				<span class="uatr-export-note"><?php esc_html_e( 'Max 50,000 rows per export.', 'unstoppable-activity-tracker' ); ?></span>
			</form>

			<table class="widefat striped uatr-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'unstoppable-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Severity', 'unstoppable-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Code', 'unstoppable-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'User', 'unstoppable-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Object', 'unstoppable-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Action', 'unstoppable-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Message', 'unstoppable-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'IP', 'unstoppable-activity-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8" style="text-align:center;padding:20px;"><?php esc_html_e( 'No events match the current filters.', 'unstoppable-activity-tracker' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$created = (float) $row['created_on'];
							$display = wp_date( 'Y-m-d H:i:s', (int) $created );
							?>
							<tr class="uatr-sev-<?php echo esc_attr( $row['severity'] ); ?>">
								<td><span title="<?php echo esc_attr( (string) $created ); ?>"><?php echo esc_html( $display ); ?></span></td>
								<td><span class="uatr-sev-pill uatr-sev-pill-<?php echo esc_attr( $row['severity'] ); ?>"><?php echo esc_html( $row['severity'] ); ?></span></td>
								<td><code><?php echo esc_html( $row['alert_id'] ); ?></code></td>
								<td><?php echo esc_html( $row['username'] ? $row['username'] : '—' ); ?></td>
								<td><?php echo esc_html( $row['object'] ); ?></td>
								<td><?php echo esc_html( $row['event_type'] ); ?></td>
								<td><?php echo esc_html( $row['message'] ); ?></td>
								<td><?php echo esc_html( $row['ip'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						$pagination_args = array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => __( '« Prev', 'unstoppable-activity-tracker' ),
							'next_text' => __( 'Next »', 'unstoppable-activity-tracker' ),
						);
						echo wp_kses_post( paginate_links( $pagination_args ) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
