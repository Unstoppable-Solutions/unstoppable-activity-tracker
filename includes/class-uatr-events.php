<?php
/**
 * Hooks WordPress actions to the logger.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates WordPress events into log entries.
 *
 * Each handler is small and delegates the actual write to UATR_Logger so
 * that all DB writes go through a single, audited code path.
 */
class UATR_Events {

	/**
	 * Logger instance.
	 *
	 * @var UATR_Logger
	 */
	private $logger;

	/**
	 * Post types we deliberately ignore (revisions, internal types, etc.).
	 *
	 * @var array
	 */
	private $ignored_post_types = array(
		'revision',
		'nav_menu_item',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'oembed_cache',
		'customize_changeset',
		'custom_css',
		'attachment',
		'shop_order_refund',
	);

	/**
	 * Watched site options — these we explicitly track changes to.
	 *
	 * @var array
	 */
	private $watched_options = array(
		'blogname',
		'blogdescription',
		'admin_email',
		'siteurl',
		'home',
		'users_can_register',
		'default_role',
		'permalink_structure',
		'WPLANG',
	);

	/**
	 * Constructor.
	 *
	 * @param UATR_Logger $logger Logger instance.
	 */
	public function __construct( UATR_Logger $logger ) {
		$this->logger = $logger;
		$this->register_hooks();
	}

	/**
	 * Wire up every WordPress action we care about.
	 */
	private function register_hooks() {
		// Authentication.
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 1 );
		add_action( 'wp_logout', array( $this, 'on_logout' ), 10, 1 );

		// Posts (create / update / status changes / delete).
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 10, 4 );
		add_action( 'transition_post_status', array( $this, 'on_post_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ), 10, 2 );
		add_action( 'untrashed_post', array( $this, 'on_post_untrashed' ), 10, 1 );

		// Users.
		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
		add_action( 'delete_user', array( $this, 'on_user_delete' ), 10, 3 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 3 );
		add_action( 'set_user_role', array( $this, 'on_set_user_role' ), 10, 3 );

		// Plugins.
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 2 );
		add_action( 'deleted_plugin', array( $this, 'on_plugin_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );

		// Themes.
		add_action( 'switch_theme', array( $this, 'on_theme_switch' ), 10, 3 );

		// Core updated.
		add_action( '_core_updated_successfully', array( $this, 'on_core_updated' ), 10, 1 );

		// Watched options — register one closure per option using the dynamic action.
		foreach ( $this->watched_options as $option ) {
			add_action(
				'update_option_' . $option,
				array( $this, 'on_watched_option_changed' ),
				10,
				3
			);
		}
	}

	/* ============================================================
	 * Authentication handlers
	 * ============================================================ */

	/**
	 * Successful login.
	 *
	 * @param string  $user_login User's login.
	 * @param WP_User $user       User object.
	 */
	public function on_login( $user_login, $user ) {
		$user_id = $user instanceof WP_User ? (int) $user->ID : 0;
		$roles   = $user instanceof WP_User && is_array( $user->roles ) ? implode( ',', $user->roles ) : '';

		$this->logger->log(
			UATR_Logger::EVENT_USER_LOGIN,
			'user',
			'login',
			sprintf(
				/* translators: %s: username */
				__( 'User %s logged in', 'unstoppable-activity-tracker' ),
				$user_login
			),
			array(
				'user_id'    => $user_id,
				'username'   => (string) $user_login,
				'user_roles' => $roles,
			)
		);
	}

	/**
	 * Failed login attempt.
	 *
	 * @param string $username Attempted username.
	 */
	public function on_login_failed( $username ) {
		$this->logger->log(
			UATR_Logger::EVENT_USER_LOGIN_FAILED,
			'user',
			'failed_login',
			sprintf(
				/* translators: %s: attempted username */
				__( 'Failed login attempt for "%s"', 'unstoppable-activity-tracker' ),
				$username
			),
			array(
				'severity' => 'warning',
				'user_id'  => 0,
				'username' => (string) $username,
				'meta'     => array( 'attempted_username' => (string) $username ),
			)
		);
	}

	/**
	 * Logout.
	 *
	 * @param int $user_id User ID being logged out.
	 */
	public function on_logout( $user_id ) {
		$user      = $user_id ? get_userdata( (int) $user_id ) : false;
		$user_login = $user ? $user->user_login : '';

		$this->logger->log(
			UATR_Logger::EVENT_USER_LOGOUT,
			'user',
			'logout',
			sprintf(
				/* translators: %s: username */
				__( 'User %s logged out', 'unstoppable-activity-tracker' ),
				$user_login
			),
			array(
				'user_id'  => (int) $user_id,
				'username' => $user_login,
			)
		);
	}

	/* ============================================================
	 * Post handlers
	 * ============================================================ */

	/**
	 * Whether to skip a given post entirely (revision, ignored type, autosave, etc.).
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	private function should_skip_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return true;
		}
		if ( in_array( $post->post_type, $this->ignored_post_types, true ) ) {
			return true;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return true;
		}
		// auto-draft is the placeholder created when you click "Add New" — not a real save.
		if ( 'auto-draft' === $post->post_status || 'inherit' === $post->post_status ) {
			return true;
		}
		return false;
	}

	/**
	 * Post created or updated. Fires after post + meta + terms are saved.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Post object.
	 * @param bool         $update      Whether this is an update.
	 * @param WP_Post|null $post_before Previous post object on update.
	 */
	public function on_post_saved( $post_id, $post, $update, $post_before ) {
		if ( $this->should_skip_post( $post ) ) {
			return;
		}

		// Status changes are handled by transition_post_status — avoid double-logging.
		if ( $update && $post_before instanceof WP_Post && $post->post_status !== $post_before->post_status ) {
			return;
		}

		if ( ! $update ) {
			$this->logger->log(
				UATR_Logger::EVENT_POST_CREATED,
				'post',
				'created',
				sprintf(
					/* translators: 1: post type, 2: post title */
					__( 'Created %1$s "%2$s"', 'unstoppable-activity-tracker' ),
					$post->post_type,
					$post->post_title
				),
				array(
					'post_title' => $post->post_title,
					'post_type'  => $post->post_type,
					'meta'       => array(
						'post_id' => (int) $post_id,
						'status'  => $post->post_status,
					),
				)
			);
			return;
		}

		// Pure content edit (status didn't change).
		$this->logger->log(
			UATR_Logger::EVENT_POST_UPDATED,
			'post',
			'updated',
			sprintf(
				/* translators: 1: post type, 2: post title */
				__( 'Updated %1$s "%2$s"', 'unstoppable-activity-tracker' ),
				$post->post_type,
				$post->post_title
			),
			array(
				'post_title' => $post->post_title,
				'post_type'  => $post->post_type,
				'meta'       => array(
					'post_id' => (int) $post_id,
					'status'  => $post->post_status,
				),
			)
		);
	}

	/**
	 * Post status changed.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_post_transition( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}
		if ( $this->should_skip_post( $post ) ) {
			return;
		}
		// Skip the initial new -> auto-draft and auto-draft -> draft jump.
		if ( 'auto-draft' === $old_status || 'auto-draft' === $new_status ) {
			return;
		}

		$base_meta = array(
			'post_id'    => (int) $post->ID,
			'old_status' => $old_status,
			'new_status' => $new_status,
		);
		$base_ctx  = array(
			'post_title' => $post->post_title,
			'post_type'  => $post->post_type,
			'meta'       => $base_meta,
		);

		// First publish.
		if ( 'publish' === $new_status && 'publish' !== $old_status && 'trash' !== $old_status ) {
			$this->logger->log(
				UATR_Logger::EVENT_POST_PUBLISHED,
				'post',
				'published',
				sprintf(
					/* translators: 1: post type, 2: post title */
					__( 'Published %1$s "%2$s"', 'unstoppable-activity-tracker' ),
					$post->post_type,
					$post->post_title
				),
				$base_ctx
			);
			return;
		}

		// Trashed.
		if ( 'trash' === $new_status ) {
			$this->logger->log(
				UATR_Logger::EVENT_POST_TRASHED,
				'post',
				'trashed',
				sprintf(
					/* translators: 1: post type, 2: post title */
					__( 'Moved %1$s "%2$s" to trash', 'unstoppable-activity-tracker' ),
					$post->post_type,
					$post->post_title
				),
				$base_ctx
			);
			return;
		}

		// Unpublished (publish -> draft/pending/private).
		if ( 'publish' === $old_status && in_array( $new_status, array( 'draft', 'pending', 'private' ), true ) ) {
			$this->logger->log(
				UATR_Logger::EVENT_POST_UNPUBLISHED,
				'post',
				'unpublished',
				sprintf(
					/* translators: 1: post type, 2: post title, 3: new status */
					__( 'Unpublished %1$s "%2$s" (now %3$s)', 'unstoppable-activity-tracker' ),
					$post->post_type,
					$post->post_title,
					$new_status
				),
				$base_ctx
			);
		}
	}

	/**
	 * Post permanently deleted.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 */
	public function on_post_deleted( $post_id, $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( $this->should_skip_post( $post ) ) {
			return;
		}

		$this->logger->log(
			UATR_Logger::EVENT_POST_DELETED,
			'post',
			'deleted',
			sprintf(
				/* translators: 1: post type, 2: post title */
				__( 'Permanently deleted %1$s "%2$s"', 'unstoppable-activity-tracker' ),
				$post->post_type,
				$post->post_title
			),
			array(
				'severity'   => 'warning',
				'post_title' => $post->post_title,
				'post_type'  => $post->post_type,
				'meta'       => array( 'post_id' => (int) $post_id ),
			)
		);
	}

	/**
	 * Post restored from trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_post_untrashed( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( $this->should_skip_post( $post ) ) {
			return;
		}

		$this->logger->log(
			UATR_Logger::EVENT_POST_RESTORED,
			'post',
			'restored',
			sprintf(
				/* translators: 1: post type, 2: post title */
				__( 'Restored %1$s "%2$s" from trash', 'unstoppable-activity-tracker' ),
				$post->post_type,
				$post->post_title
			),
			array(
				'post_title' => $post->post_title,
				'post_type'  => $post->post_type,
				'meta'       => array( 'post_id' => (int) $post_id ),
			)
		);
	}

	/* ============================================================
	 * User handlers
	 * ============================================================ */

	/**
	 * New user created.
	 *
	 * @param int $user_id New user ID.
	 */
	public function on_user_register( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		$this->logger->log(
			UATR_Logger::EVENT_USER_REGISTERED,
			'user',
			'created',
			sprintf(
				/* translators: 1: username, 2: user email */
				__( 'New user "%1$s" registered (%2$s)', 'unstoppable-activity-tracker' ),
				$user->user_login,
				$user->user_email
			),
			array(
				'meta' => array(
					'new_user_id'    => (int) $user_id,
					'new_user_login' => $user->user_login,
					'new_user_email' => $user->user_email,
					'roles'          => is_array( $user->roles ) ? $user->roles : array(),
				),
			)
		);
	}

	/**
	 * User about to be deleted.
	 *
	 * @param int      $deleted_id Deleted user ID.
	 * @param int|null $reassign   ID of reassigned user.
	 * @param WP_User  $deleted    Deleted user object.
	 */
	public function on_user_delete( $deleted_id, $reassign, $deleted ) {
		$login = $deleted instanceof WP_User ? $deleted->user_login : '';
		$email = $deleted instanceof WP_User ? $deleted->user_email : '';

		$this->logger->log(
			UATR_Logger::EVENT_USER_DELETED,
			'user',
			'deleted',
			sprintf(
				/* translators: %s: username */
				__( 'Deleted user "%s"', 'unstoppable-activity-tracker' ),
				$login
			),
			array(
				'severity' => 'warning',
				'meta'     => array(
					'deleted_user_id'    => (int) $deleted_id,
					'deleted_user_login' => $login,
					'deleted_user_email' => $email,
					'reassigned_to'      => $reassign ? (int) $reassign : null,
				),
			)
		);
	}

	/**
	 * Existing user profile updated. Diffs interesting fields.
	 *
	 * @param int     $user_id       User ID.
	 * @param WP_User $old_user_data Old data.
	 * @param array   $userdata      New data array.
	 */
	public function on_profile_update( $user_id, $old_user_data, $userdata = array() ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		$changes = array();
		if ( $old_user_data instanceof WP_User ) {
			foreach ( array( 'user_email', 'user_url', 'display_name', 'user_login' ) as $field ) {
				$old = isset( $old_user_data->$field ) ? (string) $old_user_data->$field : '';
				$new = isset( $user->$field ) ? (string) $user->$field : '';
				if ( $old !== $new ) {
					$changes[ $field ] = array(
						'from' => $old,
						'to'   => $new,
					);
				}
			}
		}

		// Avoid duplicate noise: set_user_role fires its own event for role changes.
		// We only log here if at least one tracked field changed.
		if ( empty( $changes ) ) {
			return;
		}

		$this->logger->log(
			UATR_Logger::EVENT_USER_UPDATED,
			'user',
			'updated',
			sprintf(
				/* translators: %s: username */
				__( 'Updated profile for user "%s"', 'unstoppable-activity-tracker' ),
				$user->user_login
			),
			array(
				'meta' => array(
					'updated_user_id'    => (int) $user_id,
					'updated_user_login' => $user->user_login,
					'changes'            => $changes,
				),
			)
		);
	}

	/**
	 * User role changed.
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $new_role  New role.
	 * @param string[] $old_roles Old roles.
	 */
	public function on_set_user_role( $user_id, $new_role, $old_roles ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		// Skip noise from user creation: set_user_role fires once with empty old_roles.
		if ( empty( $old_roles ) ) {
			return;
		}

		$this->logger->log(
			UATR_Logger::EVENT_USER_ROLE_CHANGED,
			'user',
			'role_changed',
			sprintf(
				/* translators: 1: username, 2: old roles, 3: new role */
				__( 'Changed role of "%1$s" from %2$s to %3$s', 'unstoppable-activity-tracker' ),
				$user->user_login,
				is_array( $old_roles ) ? implode( ',', $old_roles ) : (string) $old_roles,
				$new_role
			),
			array(
				'severity' => 'notice',
				'meta'     => array(
					'target_user_id'    => (int) $user_id,
					'target_user_login' => $user->user_login,
					'old_roles'         => is_array( $old_roles ) ? array_values( $old_roles ) : array(),
					'new_role'          => (string) $new_role,
				),
			)
		);
	}

	/* ============================================================
	 * Plugin handlers
	 * ============================================================ */

	/**
	 * Plugin activated.
	 *
	 * @param string $plugin       Plugin path.
	 * @param bool   $network_wide Network-wide.
	 */
	public function on_plugin_activated( $plugin, $network_wide ) {
		$data = $this->get_plugin_data_safe( $plugin );

		$this->logger->log(
			UATR_Logger::EVENT_PLUGIN_ACTIVATED,
			'plugin',
			'activated',
			sprintf(
				/* translators: %s: plugin name */
				__( 'Activated plugin "%s"', 'unstoppable-activity-tracker' ),
				$data['name']
			),
			array(
				// Elevated in 2.3.0 — plugin activations are a leading cause of site breakage.
				'severity' => 'notice',
				'meta'     => array(
					'plugin'       => (string) $plugin,
					'name'         => $data['name'],
					'version'      => $data['version'],
					'network_wide' => (bool) $network_wide,
					'site_state'   => $this->capture_site_state(),
				),
			)
		);
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $plugin       Plugin path.
	 * @param bool   $network_wide Network-wide.
	 */
	public function on_plugin_deactivated( $plugin, $network_wide ) {
		$data = $this->get_plugin_data_safe( $plugin );

		$this->logger->log(
			UATR_Logger::EVENT_PLUGIN_DEACTIVATED,
			'plugin',
			'deactivated',
			sprintf(
				/* translators: %s: plugin name */
				__( 'Deactivated plugin "%s"', 'unstoppable-activity-tracker' ),
				$data['name']
			),
			array(
				'meta' => array(
					'plugin'       => (string) $plugin,
					'name'         => $data['name'],
					'network_wide' => (bool) $network_wide,
				),
			)
		);
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param bool   $deleted     Whether deletion succeeded.
	 */
	public function on_plugin_deleted( $plugin_file, $deleted ) {
		if ( ! $deleted ) {
			return;
		}

		$this->logger->log(
			UATR_Logger::EVENT_PLUGIN_DELETED,
			'plugin',
			'deleted',
			sprintf(
				/* translators: %s: plugin file */
				__( 'Deleted plugin "%s"', 'unstoppable-activity-tracker' ),
				$plugin_file
			),
			array(
				'severity' => 'warning',
				'meta'     => array( 'plugin' => (string) $plugin_file ),
			)
		);
	}

	/**
	 * After WP runs an upgrader (install/update for plugin/theme/core).
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $hook_extra Context.
	 */
	public function on_upgrader_complete( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return;
		}
		$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';
		$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';

		if ( 'plugin' === $type ) {
			$this->log_plugin_upgrade( $hook_extra, $action );
			return;
		}
		if ( 'theme' === $type ) {
			$this->log_theme_upgrade( $hook_extra, $action );
		}
	}

	/**
	 * Log a plugin install/update from upgrader_process_complete.
	 *
	 * @param array  $hook_extra Hook context.
	 * @param string $action     install|update.
	 */
	private function log_plugin_upgrade( $hook_extra, $action ) {
		$plugins = array();
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = $hook_extra['plugins'];
		} elseif ( isset( $hook_extra['plugin'] ) ) {
			$plugins = array( $hook_extra['plugin'] );
		}

		foreach ( $plugins as $plugin ) {
			$data = $this->get_plugin_data_safe( $plugin );
			if ( 'install' === $action ) {
				$this->logger->log(
					UATR_Logger::EVENT_PLUGIN_INSTALLED,
					'plugin',
					'installed',
					sprintf(
						/* translators: 1: plugin name, 2: version */
						__( 'Installed plugin "%1$s" %2$s', 'unstoppable-activity-tracker' ),
						$data['name'],
						$data['version']
					),
					array(
						// Elevated in 2.3.0 — fresh installs are a leading cause of site breakage.
						'severity' => 'notice',
						'meta'     => array(
							'plugin'     => (string) $plugin,
							'name'       => $data['name'],
							'version'    => $data['version'],
							'site_state' => $this->capture_site_state(),
						),
					)
				);
			} elseif ( 'update' === $action ) {
				$this->logger->log(
					UATR_Logger::EVENT_PLUGIN_UPDATED,
					'plugin',
					'updated',
					sprintf(
						/* translators: 1: plugin name, 2: version */
						__( 'Updated plugin "%1$s" to %2$s', 'unstoppable-activity-tracker' ),
						$data['name'],
						$data['version']
					),
					array(
						// Elevated in 2.3.0 — plugin updates are the #1 cause of site breakage.
						'severity' => 'notice',
						'meta'     => array(
							'plugin'     => (string) $plugin,
							'name'       => $data['name'],
							'version'    => $data['version'],
							'site_state' => $this->capture_site_state(),
						),
					)
				);
			}
		}
	}

	/**
	 * Log a theme install/update from upgrader_process_complete.
	 *
	 * @param array  $hook_extra Hook context.
	 * @param string $action     install|update.
	 */
	private function log_theme_upgrade( $hook_extra, $action ) {
		$themes = array();
		if ( isset( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
			$themes = $hook_extra['themes'];
		} elseif ( isset( $hook_extra['theme'] ) ) {
			$themes = array( $hook_extra['theme'] );
		}

		foreach ( $themes as $theme_slug ) {
			$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme( $theme_slug ) : null;
			$name  = ( $theme && $theme->exists() ) ? (string) $theme->get( 'Name' ) : (string) $theme_slug;
			$ver   = ( $theme && $theme->exists() ) ? (string) $theme->get( 'Version' ) : '';

			$code  = ( 'install' === $action ) ? UATR_Logger::EVENT_THEME_INSTALLED : UATR_Logger::EVENT_THEME_UPDATED;
			$type  = ( 'install' === $action ) ? 'installed' : 'updated';
			/* translators: 1: theme name, 2: version */
			$msg_install = __( 'Installed theme "%1$s" %2$s', 'unstoppable-activity-tracker' );
			/* translators: 1: theme name, 2: version */
			$msg_update  = __( 'Updated theme "%1$s" to %2$s', 'unstoppable-activity-tracker' );

			$this->logger->log(
				$code,
				'theme',
				$type,
				sprintf( ( 'install' === $action ) ? $msg_install : $msg_update, $name, $ver ),
				array(
					'meta' => array(
						'theme'   => (string) $theme_slug,
						'name'    => $name,
						'version' => $ver,
					),
				)
			);
		}
	}

	/* ============================================================
	 * Theme & core handlers
	 * ============================================================ */

	/**
	 * Theme switched.
	 *
	 * @param string   $new_name  New theme name.
	 * @param WP_Theme $new_theme New theme.
	 * @param WP_Theme $old_theme Old theme.
	 */
	public function on_theme_switch( $new_name, $new_theme, $old_theme ) {
		$old_name = ( $old_theme && method_exists( $old_theme, 'get' ) ) ? (string) $old_theme->get( 'Name' ) : '';

		$this->logger->log(
			UATR_Logger::EVENT_THEME_SWITCHED,
			'theme',
			'switched',
			sprintf(
				/* translators: 1: old theme, 2: new theme */
				__( 'Switched theme from "%1$s" to "%2$s"', 'unstoppable-activity-tracker' ),
				$old_name,
				$new_name
			),
			array(
				// Elevated in 2.3.0 — theme switches are a high-risk breakage trigger.
				'severity' => 'warning',
				'meta'     => array(
					'old_theme'  => $old_name,
					'new_theme'  => (string) $new_name,
					'site_state' => $this->capture_site_state(),
				),
			)
		);
	}

	/**
	 * WordPress core successfully updated.
	 *
	 * @param string $wp_version New WP version.
	 */
	public function on_core_updated( $wp_version ) {
		$this->logger->log(
			UATR_Logger::EVENT_CORE_UPDATED,
			'core',
			'updated',
			sprintf(
				/* translators: %s: WordPress version */
				__( 'WordPress core updated to %s', 'unstoppable-activity-tracker' ),
				$wp_version
			),
			array(
				// Elevated in 2.3.0 — core updates are a known cause of plugin/theme incompatibility crashes.
				'severity' => 'warning',
				'meta'     => array(
					'wp_version' => (string) $wp_version,
					'site_state' => $this->capture_site_state(),
				),
			)
		);
	}

	/* ============================================================
	 * Watched options
	 * ============================================================ */

	/**
	 * Generic handler for the dynamic update_option_{$option} action.
	 *
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 * @param string $option    Option name.
	 */
	public function on_watched_option_changed( $old_value, $new_value, $option ) {
		// Only emit when something actually changed.
		if ( $old_value === $new_value ) {
			return;
		}

		// Severity ladder (added in 2.3.0): some option changes near-universally lock the
		// site admin out or break front-end rendering. Elevate those so they're visually
		// obvious in the Activity Log after an outage.
		$critical_options = array( 'siteurl', 'home' );
		$warning_options  = array( 'permalink_structure', 'WPLANG', 'users_can_register', 'default_role' );

		if ( in_array( $option, $critical_options, true ) ) {
			$severity = 'critical';
		} elseif ( in_array( $option, $warning_options, true ) ) {
			$severity = 'warning';
		} else {
			$severity = 'notice';
		}

		$meta = array(
			'option' => (string) $option,
			'old'    => is_scalar( $old_value ) ? (string) $old_value : wp_json_encode( $old_value ),
			'new'    => is_scalar( $new_value ) ? (string) $new_value : wp_json_encode( $new_value ),
		);

		// Snapshot site state on the high-risk option changes so post-mortem has context.
		if ( 'notice' !== $severity ) {
			$meta['site_state'] = $this->capture_site_state();
		}

		$this->logger->log(
			UATR_Logger::EVENT_OPTION_CHANGED,
			'option',
			'changed',
			sprintf(
				/* translators: %s: option name */
				__( 'Changed setting "%s"', 'unstoppable-activity-tracker' ),
				$option
			),
			array(
				'severity' => $severity,
				'meta'     => $meta,
			)
		);
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	/**
	 * Read plugin metadata, falling back to the file basename.
	 *
	 * @param string $plugin_file Plugin path relative to /wp-content/plugins/.
	 * @return array{name:string, version:string}
	 */
	private function get_plugin_data_safe( $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( file_exists( $path ) && function_exists( 'get_plugin_data' ) ) {
			$data = get_plugin_data( $path, false, false );
			return array(
				'name'    => isset( $data['Name'] ) && '' !== $data['Name'] ? (string) $data['Name'] : (string) $plugin_file,
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			);
		}
		return array(
			'name'    => (string) $plugin_file,
			'version' => '',
		);
	}

	/**
	 * Snapshot the current site state for post-mortem use (added in 2.3.0).
	 *
	 * Called when a potentially site-breaking event is logged (plugin/core/theme change,
	 * critical option change). The snapshot is stored in the event's `meta` JSON so
	 * that after an outage, the Activity Log shows the exact plugin/theme/version state
	 * at the moment of each risky change — enough context to know what to roll back.
	 *
	 * Capture is intentionally minimal: identifiers and versions only, no settings data.
	 *
	 * @return array{active_plugins:string[], wp_version:string, active_theme:string, theme_version:string, php_version:string}
	 */
	private function capture_site_state() {
		// Active plugins list — basename/slug paths like "akismet/akismet.php".
		$active_plugins = (array) get_option( 'active_plugins', array() );

		// Active theme. wp_get_theme() can fail during early bootstrap; guard defensively.
		$theme_name    = '';
		$theme_version = '';
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( $theme && $theme->exists() ) {
				$theme_name    = (string) $theme->get( 'Name' );
				$theme_version = (string) $theme->get( 'Version' );
			}
		}

		return array(
			'active_plugins' => array_values( array_map( 'strval', $active_plugins ) ),
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'active_theme'   => $theme_name,
			'theme_version'  => $theme_version,
			'php_version'    => PHP_VERSION,
		);
	}
}
