<?php
/**
 * Debug Log viewer — complement to the Activity Log for post-incident forensics.
 *
 * The Activity Log records what users did on the site. The Debug Log records
 * what the PHP code did (fatals, warnings, deprecations, DB errors). Side by
 * side they give the complete "what happened" picture for any incident:
 * correlate a plugin update in the Activity Log against a fatal in the Debug
 * Log at the same timestamp and the cause is usually obvious.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page: Debug Log viewer.
 */
class UATR_Debug_Log {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'unstoppable-activity-tracker-debug';

	/**
	 * Default number of lines to tail from the log.
	 */
	const DEFAULT_TAIL_LINES = 500;

	/**
	 * Hard cap on lines returned per view — protects against unbounded memory
	 * use if someone bumps the query param manually.
	 */
	const MAX_TAIL_LINES = 5000;

	/**
	 * Tail-read buffer size (bytes read per seek-backward iteration).
	 */
	const TAIL_CHUNK_SIZE = 8192;

	/**
	 * Maximum bytes streamed in a single download. Debug logs on long-running
	 * sites can reach hundreds of MB, and the response is usually rejected
	 * upstream (Cloudflare caps response bodies around 100 MB on free/pro
	 * plans, mod_security inspects the first ~512 KB, reverse proxies often
	 * have their own caps). When the log is larger than this, we stream only
	 * the tail — users downloading a debug log almost always want recent
	 * errors, not full history, and anyone who needs the complete file can
	 * grab it via SFTP.
	 *
	 * Overridable via the `uatr_debug_log_download_max_bytes` filter.
	 */
	const DOWNLOAD_MAX_BYTES = 26214400; // 25 MB.

	/**
	 * Chunk size for the download streaming loop. Larger than TAIL_CHUNK_SIZE
	 * because the download walks forward through the file and we want to
	 * minimise syscall / flush overhead, not seek precision.
	 */
	const DOWNLOAD_CHUNK_SIZE = 65536; // 64 KB.

	/**
	 * Severity map used when rendering parsed entries.
	 *
	 * @var array<string,string>
	 */
	private static $severity_map = array(
		'fatal'       => 'critical',
		'parse'       => 'critical',
		'error'       => 'error',
		'warning'     => 'warning',
		'db'          => 'warning',
		'notice'      => 'notice',
		'deprecated'  => 'info',
		'strict'      => 'info',
		'unknown'     => 'info',
	);

	/**
	 * Hook: handle clear/download actions before the page renders.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/* ================================================================
	 * Log-path resolution
	 * ================================================================ */

	/**
	 * Resolve the debug log path from WordPress constants.
	 *
	 * WP_DEBUG_LOG can be:
	 *   - true           → default path: WP_CONTENT_DIR/debug.log
	 *   - a string path  → that exact absolute path
	 *   - false / unset  → debug logging disabled
	 *
	 * @return string|null Resolved absolute path, or null if logging is disabled.
	 */
	public static function resolve_log_path() {
		if ( ! defined( 'WP_DEBUG_LOG' ) || false === WP_DEBUG_LOG ) {
			return null;
		}
		if ( true === WP_DEBUG_LOG ) {
			return trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
		}
		if ( is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		return null;
	}

	/**
	 * Get a snapshot of the current debug state for the status card.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_debug_state() {
		$path = self::resolve_log_path();
		$state = array(
			'wp_debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'   => defined( 'WP_DEBUG_LOG' ) && false !== WP_DEBUG_LOG,
			'path'           => $path,
			'exists'         => false,
			'readable'       => false,
			'writable'       => false,
			'size'           => 0,
			'modified'       => 0,
		);
		if ( null !== $path && file_exists( $path ) ) {
			$state['exists']   = true;
			$state['readable'] = is_readable( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking writability on the ABSOLUTE WP_DEBUG_LOG path to decide whether the "Clear log" button should be shown. WP_Filesystem would require admin FS credentials on non-direct hosts just to answer this read-only capability check, which is inappropriate for a status display.
			$state['writable'] = is_writable( $path );
			$state['size']     = (int) filesize( $path );
			$state['modified'] = (int) filemtime( $path );
		}
		return $state;
	}

	/* ================================================================
	 * Tail-read
	 * ================================================================ */

	/**
	 * Read approximately the last N lines of a file without loading the
	 * whole file into memory. Seeks backward in TAIL_CHUNK_SIZE blocks.
	 *
	 * @param string $path  Absolute file path.
	 * @param int    $lines Maximum lines to return.
	 * @return string Tail content (may start mid-line if the file is huge).
	 */
	public static function tail_file( $path, $lines ) {
		$lines = max( 1, min( self::MAX_TAIL_LINES, (int) $lines ) );
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		// Direct PHP file operations are required for efficient tail-reading
		// of a system log at an ABSOLUTE path (the resolved WP_DEBUG_LOG path,
		// typically wp-content/debug.log). WP_Filesystem has no byte-level
		// seek API, so a tail-read of a multi-hundred-MB debug.log via
		// WP_Filesystem would require reading the entire file — defeating the
		// whole purpose of this method. See CLAUDE.md for the OOM-safety story.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			return '';
		}

		fseek( $fp, 0, SEEK_END );
		$position = ftell( $fp );
		$filesize = $position;
		if ( 0 === $filesize ) {
			fclose( $fp );
			return '';
		}

		$output   = '';
		$newlines = 0;

		while ( $position > 0 && $newlines <= $lines ) {
			$chunk_size = (int) min( self::TAIL_CHUNK_SIZE, $position );
			$position  -= $chunk_size;
			fseek( $fp, $position, SEEK_SET );
			$chunk    = (string) fread( $fp, $chunk_size );
			$output   = $chunk . $output;
			$newlines = substr_count( $output, "\n" );
		}

		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Trim to exactly $lines lines from the end. A trailing newline on
		// the final line creates an empty tail element after explode() — drop
		// that so "last 500 lines" means 500 lines of content, not 499 + 1 empty.
		$arr = explode( "\n", $output );
		if ( '' === end( $arr ) ) {
			array_pop( $arr );
		}
		if ( count( $arr ) > $lines ) {
			$arr = array_slice( $arr, -$lines );
		}
		return implode( "\n", $arr );
	}

	/* ================================================================
	 * Parsing
	 * ================================================================ */

	/**
	 * Parse WordPress / PHP debug log output into structured entries.
	 *
	 * WordPress writes lines like:
	 *   [21-Nov-2026 15:29:03 UTC] PHP Warning:  Undefined array key "foo" in /path/file.php on line 12
	 *   [21-Nov-2026 15:29:04 UTC] PHP Fatal error:  Uncaught Error: ...
	 *   [21-Nov-2026 15:29:05 UTC] WordPress database error Duplicate entry ...
	 *
	 * Multi-line stack traces that follow a fatal are attached to that entry.
	 *
	 * @param string $raw Raw tail content.
	 * @return array<int,array<string,string>>
	 */
	public static function parse_entries( $raw ) {
		if ( '' === $raw ) {
			return array();
		}

		$entries = array();
		$current = null;
		$lines   = explode( "\n", $raw );

		foreach ( $lines as $line ) {
			// Timestamp pattern: [21-Nov-2026 15:29:03 UTC] or [21-Nov-2026 15:29:03 America/New_York]
			if ( preg_match( '/^\[([0-9]{1,2}-[A-Za-z]{3}-[0-9]{4} [0-9:]+(?: [A-Za-z_\/\-]+)?)\]\s*(.*)$/', $line, $m ) ) {
				if ( null !== $current ) {
					$entries[] = $current;
				}
				$message = trim( $m[2] );
				$current = array(
					'time'     => $m[1],
					'severity' => self::detect_severity( $message ),
					'message'  => $message,
				);
			} elseif ( null !== $current && '' !== trim( $line ) ) {
				// Continuation of previous entry (stack trace, context).
				$current['message'] .= "\n" . $line;
			}
		}

		if ( null !== $current ) {
			$entries[] = $current;
		}

		return array_reverse( $entries );
	}

	/**
	 * Classify a raw log message into one of the known severity keys.
	 *
	 * @param string $message Raw message body (without the timestamp prefix).
	 * @return string Severity key — see self::$severity_map.
	 */
	private static function detect_severity( $message ) {
		$m = strtolower( $message );
		if ( false !== strpos( $m, 'php fatal' ) || false !== strpos( $m, 'uncaught' ) ) {
			return 'fatal';
		}
		if ( false !== strpos( $m, 'php parse' ) ) {
			return 'parse';
		}
		if ( false !== strpos( $m, 'wordpress database error' ) ) {
			return 'db';
		}
		if ( false !== strpos( $m, 'php warning' ) || false !== strpos( $m, 'php core warning' ) ) {
			return 'warning';
		}
		if ( false !== strpos( $m, 'php deprecated' ) ) {
			return 'deprecated';
		}
		if ( false !== strpos( $m, 'php notice' ) || false !== strpos( $m, 'php user notice' ) ) {
			return 'notice';
		}
		if ( false !== strpos( $m, 'php strict' ) ) {
			return 'strict';
		}
		if ( false !== strpos( $m, 'php error' ) || false !== strpos( $m, 'error' ) ) {
			return 'error';
		}
		return 'unknown';
	}

	/* ================================================================
	 * Actions
	 * ================================================================ */

	/**
	 * Dispatch POST/GET actions (clear, download) before any page output.
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page-check only; real actions are nonce-verified below.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Clear log.
		if ( isset( $_POST['uatr_debug_clear'] ) ) {
			check_admin_referer( 'uatr_debug_clear' );
			$this->clear_log();
			wp_safe_redirect( add_query_arg( array( 'cleared' => '1' ), menu_page_url( self::PAGE_SLUG, false ) ) );
			exit;
		}

		// Download log.
		if ( isset( $_GET['uatr_debug_download'] ) ) {
			check_admin_referer( 'uatr_debug_download' );
			$this->download_log();
			exit;
		}
	}

	/**
	 * Truncate the debug log file to zero bytes.
	 *
	 * We truncate rather than unlink so WordPress keeps writing to the same
	 * file descriptor without error (no need for PHP-FPM to re-open).
	 */
	private function clear_log() {
		$path = self::resolve_log_path();
		// Direct filesystem ops on the ABSOLUTE WP_DEBUG_LOG path. WP_Filesystem
		// is wrong here: we specifically want to truncate the file to zero bytes
		// (not unlink it), preserving the open file descriptor WordPress and
		// PHP-FPM may already hold — so WordPress keeps writing to the same FD
		// without needing a restart. WP_Filesystem has no truncate primitive.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( null === $path || ! file_exists( $path ) || ! is_writable( $path ) ) {
			return;
		}
		$fp = @fopen( $path, 'w' );
		if ( $fp ) {
			fclose( $fp );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Stream the debug log to the browser as a download.
	 *
	 * Caps the response at DOWNLOAD_MAX_BYTES (default 25 MB). Logs larger
	 * than the cap stream only the TAIL — the last cap-bytes of the file.
	 * The exported filename reflects which mode was used.
	 *
	 * Why the cap: a raw stream of a 500 MB debug.log through WordPress is
	 * routinely rejected upstream with HTTP 413 (Cloudflare 100 MB response
	 * cap on free/pro, mod_security SecResponseBodyLimit, reverse-proxy
	 * limits) and would in any case take minutes to transfer. Users opening
	 * a debug log want recent errors; anyone who needs the complete file
	 * should grab it via SFTP.
	 *
	 * Buffer handling: WordPress admin requests often have one or more
	 * output buffers active (for ob_gzhandler, admin notices, etc.). Without
	 * flushing those, `echo` inside this loop accumulates the entire file
	 * in PHP memory before the response leaves the server — a classic OOM
	 * on big logs. We drop every active buffer before the first byte.
	 */
	private function download_log() {
		$path = self::resolve_log_path();
		if ( null === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Debug log is not readable.', 'unstoppable-activity-tracker' ) );
		}

		$full_size  = (int) filesize( $path );

		/**
		 * Filter the per-download byte cap. Return 0 to stream the full file
		 * regardless of size (not recommended behind Cloudflare or a WAF).
		 *
		 * @param int    $max_bytes Default DOWNLOAD_MAX_BYTES.
		 * @param int    $full_size Actual size of the debug log file in bytes.
		 * @param string $path      Absolute path of the debug log.
		 */
		$max_bytes  = (int) apply_filters( 'uatr_debug_log_download_max_bytes', self::DOWNLOAD_MAX_BYTES, $full_size, $path );
		$tail_only  = $max_bytes > 0 && $full_size > $max_bytes;
		$send_bytes = $tail_only ? $max_bytes : $full_size;
		$filename   = $tail_only
			? 'debug-tail-' . size_format( $max_bytes, 0 ) . '-' . gmdate( 'Y-m-d-His' ) . '.log'
			: 'debug-' . gmdate( 'Y-m-d-His' ) . '.log';
		$filename   = str_replace( ' ', '', $filename );

		// Drop every active output buffer so we stream directly to the socket.
		// Must happen BEFORE any header() call or echo.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) $send_bytes );
		if ( $tail_only ) {
			// Hint to the client that this is a partial representation of the
			// resource. Not required (we set Content-Length to the clip size),
			// but helpful for anyone inspecting headers.
			header( 'X-UATR-Debug-Log-Truncated: tail; original-bytes=' . (string) $full_size );
		}

		// Streaming the log file to the browser as an attachment download.
		// WP_Filesystem has no streaming API (get_contents() would load the
		// entire file into memory and OOM on large logs) — direct fopen+fread
		// lets us chunk-stream and keep memory flat.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = @fopen( $path, 'rb' );
		if ( $fp ) {
			if ( $tail_only ) {
				// Seek to (filesize - max_bytes); subsequent reads stream the
				// last max_bytes bytes of the file.
				fseek( $fp, $full_size - $max_bytes, SEEK_SET );
			}
			while ( ! feof( $fp ) ) {
				echo fread( $fp, self::DOWNLOAD_CHUNK_SIZE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw file stream for download.
				@flush(); // Push each chunk out immediately; keeps memory flat and lets the client see progress.
			}
			fclose( $fp );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/* ================================================================
	 * Rendering
	 * ================================================================ */

	/**
	 * Render the Debug Log admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-activity-tracker' ) );
		}

		$state       = self::get_debug_state();
		$lines       = isset( $_GET['lines'] ) ? max( 50, min( self::MAX_TAIL_LINES, (int) $_GET['lines'] ) ) : self::DEFAULT_TAIL_LINES; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display setting.
		$sev_filter  = isset( $_GET['sev'] ) ? sanitize_key( wp_unslash( $_GET['sev'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cleared     = isset( $_GET['cleared'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$entries = array();
		if ( $state['exists'] && $state['readable'] ) {
			$raw     = self::tail_file( $state['path'], $lines );
			$entries = self::parse_entries( $raw );
			if ( '' !== $sev_filter ) {
				$entries = array_values(
					array_filter(
						$entries,
						static function ( $e ) use ( $sev_filter ) {
							return $e['severity'] === $sev_filter;
						}
					)
				);
			}
			if ( '' !== $search ) {
				$needle  = $search;
				$entries = array_values(
					array_filter(
						$entries,
						static function ( $e ) use ( $needle ) {
							return false !== stripos( $e['message'], $needle );
						}
					)
				);
			}
		}

		?>
		<div class="wrap uatr-wrap uatr-debug-wrap">
			<h1><?php esc_html_e( 'Debug Log', 'unstoppable-activity-tracker' ); ?></h1>
			<p class="uatr-intro">
				<?php esc_html_e( 'The last PHP errors, warnings, and notices from this site. Pair with the Activity Log to correlate code errors against user actions.', 'unstoppable-activity-tracker' ); ?>
			</p>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Debug log cleared.', 'unstoppable-activity-tracker' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_status_card( $state ); ?>

			<?php if ( $state['exists'] && $state['readable'] ) : ?>
				<?php $this->render_toolbar( $lines, $sev_filter, $search, $state ); ?>
				<?php $this->render_entries( $entries, $lines ); ?>
			<?php elseif ( ! $state['wp_debug_log'] ) : ?>
				<?php $this->render_enable_help(); ?>
			<?php elseif ( null === $state['path'] ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'WP_DEBUG_LOG is enabled but its value could not be resolved to a path.', 'unstoppable-activity-tracker' ); ?></p>
				</div>
			<?php else : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: %s: log file path */
							esc_html__( 'The debug log at %s does not exist yet. It will appear here as soon as WordPress or a plugin logs something.', 'unstoppable-activity-tracker' ),
							'<code>' . esc_html( $state['path'] ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Status card — WP_DEBUG state, resolved path, file size.
	 *
	 * @param array $state State from get_debug_state().
	 */
	private function render_status_card( $state ) {
		$pill = static function ( $on ) {
			$label = $on ? __( 'ON', 'unstoppable-activity-tracker' ) : __( 'OFF', 'unstoppable-activity-tracker' );
			$class = $on ? 'uatr-pill-on' : 'uatr-pill-off';
			return '<span class="uatr-debug-pill ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
		};
		?>
		<div class="uatr-debug-status">
			<div class="uatr-debug-status-row">
				<span class="uatr-debug-status-label"><?php esc_html_e( 'WP_DEBUG', 'unstoppable-activity-tracker' ); ?></span>
				<?php echo $pill( $state['wp_debug'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pill HTML built above with escaping. ?>
			</div>
			<div class="uatr-debug-status-row">
				<span class="uatr-debug-status-label"><?php esc_html_e( 'WP_DEBUG_LOG', 'unstoppable-activity-tracker' ); ?></span>
				<?php echo $pill( $state['wp_debug_log'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pill HTML built above with escaping. ?>
			</div>
			<?php if ( null !== $state['path'] ) : ?>
				<div class="uatr-debug-status-row">
					<span class="uatr-debug-status-label"><?php esc_html_e( 'Log path', 'unstoppable-activity-tracker' ); ?></span>
					<code class="uatr-debug-path"><?php echo esc_html( $state['path'] ); ?></code>
				</div>
			<?php endif; ?>
			<?php if ( $state['exists'] ) : ?>
				<div class="uatr-debug-status-row">
					<span class="uatr-debug-status-label"><?php esc_html_e( 'Size', 'unstoppable-activity-tracker' ); ?></span>
					<span><?php echo esc_html( size_format( $state['size'], 1 ) ); ?></span>
				</div>
				<div class="uatr-debug-status-row">
					<span class="uatr-debug-status-label"><?php esc_html_e( 'Last written', 'unstoppable-activity-tracker' ); ?></span>
					<span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: human-readable interval, 2: absolute timestamp */
								__( '%1$s ago (%2$s)', 'unstoppable-activity-tracker' ),
								human_time_diff( $state['modified'], time() ),
								wp_date( 'Y-m-d H:i:s', $state['modified'] )
							)
						);
						?>
					</span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Toolbar: lines / severity / search / clear / download.
	 *
	 * @param int    $lines      Current tail line count.
	 * @param string $sev_filter Current severity filter.
	 * @param string $search     Current search string.
	 */
	private function render_toolbar( $lines, $sev_filter, $search, $state = array() ) {
		$severities = array(
			''           => __( 'All severities', 'unstoppable-activity-tracker' ),
			'fatal'      => __( 'Fatal / Uncaught', 'unstoppable-activity-tracker' ),
			'parse'      => __( 'Parse errors', 'unstoppable-activity-tracker' ),
			'error'      => __( 'Errors', 'unstoppable-activity-tracker' ),
			'warning'    => __( 'Warnings', 'unstoppable-activity-tracker' ),
			'db'         => __( 'Database errors', 'unstoppable-activity-tracker' ),
			'notice'     => __( 'Notices', 'unstoppable-activity-tracker' ),
			'deprecated' => __( 'Deprecated', 'unstoppable-activity-tracker' ),
		);
		$download_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'                 => self::PAGE_SLUG,
					'uatr_debug_download'  => '1',
				),
				admin_url( 'admin.php' )
			),
			'uatr_debug_download'
		);

		// Decide the download button's label based on whether the log exceeds
		// the per-download cap. Mirrors the logic in download_log() so the
		// button tells the truth about what the user will receive.
		$file_size   = isset( $state['size'] ) ? (int) $state['size'] : 0;
		$max_bytes   = (int) apply_filters( 'uatr_debug_log_download_max_bytes', self::DOWNLOAD_MAX_BYTES, $file_size, isset( $state['path'] ) ? $state['path'] : '' );
		$will_tail   = $max_bytes > 0 && $file_size > $max_bytes;
		if ( $will_tail ) {
			$download_label = sprintf(
				/* translators: 1: tail size (e.g. "25 MB"), 2: full file size (e.g. "450 MB") */
				__( 'Download last %1$s of %2$s', 'unstoppable-activity-tracker' ),
				size_format( $max_bytes, 0 ),
				size_format( $file_size, 1 )
			);
			$download_title = __( 'The log is larger than this plugin will stream in one request (hosts and CDNs reject very large responses with HTTP 413). Download the tail here, or grab the complete file via SFTP.', 'unstoppable-activity-tracker' );
		} else {
			$download_label = __( 'Download full log', 'unstoppable-activity-tracker' );
			$download_title = '';
		}
		?>
		<form method="get" class="uatr-debug-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<label>
				<?php esc_html_e( 'Lines', 'unstoppable-activity-tracker' ); ?>
				<input type="number" name="lines" value="<?php echo esc_attr( (string) $lines ); ?>" min="50" max="<?php echo esc_attr( (string) self::MAX_TAIL_LINES ); ?>" step="50" />
			</label>
			<label>
				<?php esc_html_e( 'Severity', 'unstoppable-activity-tracker' ); ?>
				<select name="sev">
					<?php foreach ( $severities as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $sev_filter, $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Search', 'unstoppable-activity-tracker' ); ?>
				<input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'substring match', 'unstoppable-activity-tracker' ); ?>" />
			</label>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Apply', 'unstoppable-activity-tracker' ); ?></button>
			<a class="button" href="<?php echo esc_url( menu_page_url( self::PAGE_SLUG, false ) ); ?>"><?php esc_html_e( 'Reset', 'unstoppable-activity-tracker' ); ?></a>

			<span class="uatr-debug-toolbar-right">
				<a class="button" href="<?php echo esc_url( $download_url ); ?>"<?php if ( '' !== $download_title ) : ?> title="<?php echo esc_attr( $download_title ); ?>"<?php endif; ?>><?php echo esc_html( $download_label ); ?></a>
				<form method="post" class="uatr-debug-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Clear the entire debug log? This cannot be undone.', 'unstoppable-activity-tracker' ) ); ?>');">
					<?php wp_nonce_field( 'uatr_debug_clear' ); ?>
					<input type="hidden" name="uatr_debug_clear" value="1" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Clear log', 'unstoppable-activity-tracker' ); ?></button>
				</form>
			</span>
		</form>
		<?php
	}

	/**
	 * Entries table.
	 *
	 * @param array $entries Parsed entries (newest first).
	 * @param int   $lines   Tail size that was requested (for empty-state copy).
	 */
	private function render_entries( $entries, $lines ) {
		?>
		<p class="uatr-debug-count">
			<?php
			printf(
				/* translators: 1: entry count, 2: requested tail lines */
				esc_html( _n( '%1$s entry (from the last %2$s lines).', '%1$s entries (from the last %2$s lines).', count( $entries ), 'unstoppable-activity-tracker' ) ),
				esc_html( number_format_i18n( count( $entries ) ) ),
				esc_html( number_format_i18n( $lines ) )
			);
			?>
		</p>

		<?php if ( empty( $entries ) ) : ?>
			<div class="uatr-debug-empty">
				<p><strong><?php esc_html_e( 'No matching entries.', 'unstoppable-activity-tracker' ); ?></strong></p>
				<p><?php esc_html_e( 'If the log file is not empty, try widening the tail size, clearing the search, or picking "All severities".', 'unstoppable-activity-tracker' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped uatr-debug-table">
			<thead>
				<tr>
					<th scope="col" style="width:160px;"><?php esc_html_e( 'When', 'unstoppable-activity-tracker' ); ?></th>
					<th scope="col" style="width:110px;"><?php esc_html_e( 'Severity', 'unstoppable-activity-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'unstoppable-activity-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $e ) : ?>
					<?php
					$pill_class = 'uatr-sev-pill-' . esc_attr( self::$severity_map[ $e['severity'] ] ?? 'info' );
					?>
					<tr>
						<td class="uatr-debug-time"><?php echo esc_html( $e['time'] ); ?></td>
						<td><span class="uatr-sev-pill <?php echo esc_attr( $pill_class ); ?>"><?php echo esc_html( strtoupper( $e['severity'] ) ); ?></span></td>
						<td>
							<?php if ( false === strpos( $e['message'], "\n" ) ) : ?>
								<code class="uatr-debug-msg"><?php echo esc_html( $e['message'] ); ?></code>
							<?php else : ?>
								<details>
									<summary><code class="uatr-debug-msg"><?php echo esc_html( strtok( $e['message'], "\n" ) ); ?></code></summary>
									<pre class="uatr-debug-stack"><?php echo esc_html( $e['message'] ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Help block shown when WP_DEBUG_LOG is not enabled.
	 */
	private function render_enable_help() {
		$snippet = "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\n@ini_set( 'display_errors', 0 );";
		?>
		<div class="uatr-debug-help">
			<h2><?php esc_html_e( 'Debug logging is not enabled yet', 'unstoppable-activity-tracker' ); ?></h2>
			<p>
				<?php esc_html_e( "WordPress won't record PHP errors to a log file until you turn on WP_DEBUG_LOG. Edit your site's wp-config.php (just above the \"That's all, stop editing!\" line) and add:", 'unstoppable-activity-tracker' ); ?>
			</p>
			<pre class="uatr-debug-snippet"><?php echo esc_html( $snippet ); ?></pre>
			<p class="description">
				<?php esc_html_e( 'This plugin never edits wp-config.php for you — editing it incorrectly can take a site offline. Make the change yourself, test the site, then refresh this page.', 'unstoppable-activity-tracker' ); ?>
			</p>
		</div>
		<?php
	}
}
