<?php
/**
 * UATR benchmark harness — scenario runner.
 *
 * Usage:
 *   php tests/benchmarks/run.php --scenario=stats
 *   php tests/benchmarks/run.php --scenario=retention
 *   php tests/benchmarks/run.php --scenario=concurrent
 *   php tests/benchmarks/run.php --scenario=stale-lock
 *   php tests/benchmarks/run.php --scenario=all
 *
 * Output is tab-separated: scenario<TAB>rows<TAB>metric<TAB>value
 *
 * @package Unstoppable_Activity_Tracker
 */

require __DIR__ . '/bootstrap.php';

$scenario = isset( $uatr_bench_args['scenario'] ) ? $uatr_bench_args['scenario'] : 'all';

global $wpdb;
$table = UATR_Installer::get_table_name();

/**
 * Scenario (i): /stats query timing.
 */
function uatr_bench_stats( $wpdb, $table ) {
	$rows   = uatr_bench_row_count();
	$cutoff = microtime( true ) - DAY_IN_SECONDS;

	$ms_total = uatr_bench_time(
		function () use ( $wpdb, $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark harness.
			$wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
	);
	uatr_bench_emit( 'stats', (string) $rows, 'count_total', number_format( $ms_total, 2, '.', '' ) );

	$ms_24h = uatr_bench_time(
		function () use ( $wpdb, $table, $cutoff ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark harness.
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_on >= %f", $cutoff ) );
		}
	);
	uatr_bench_emit( 'stats', (string) $rows, 'count_24h', number_format( $ms_24h, 2, '.', '' ) );

	$ms_group = uatr_bench_time(
		function () use ( $wpdb, $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark harness.
			$wpdb->get_results( "SELECT object, COUNT(*) AS cnt FROM {$table} GROUP BY object" );
		}
	);
	uatr_bench_emit( 'stats', (string) $rows, 'group_by_object', number_format( $ms_group, 2, '.', '' ) );
}

/**
 * Scenario (ii) + (iii): end-to-end retention pass + single-batch DELETE.
 *
 * Measures one bare DELETE … LIMIT 1000 (the smallest unit of interruptible
 * work) separately from a full run_cleanup() call. Requires retention_days
 * to be set to a value that makes most of the seeded rows match the cutoff
 * — the seeder spreads events across the last 365 days, so retention_days=30
 * marks ~335/365 of the rows as expired.
 */
function uatr_bench_retention( $wpdb, $table ) {
	$rows_before = uatr_bench_row_count();

	// Force a short retention for this benchmark so most seeded rows expire.
	$original_retention = get_option( 'uatr_retention_days' );
	update_option( 'uatr_retention_days', 30 );

	// Scenario (iii): single-batch DELETE cost under realistic conditions.
	// Measure one bare DELETE … LIMIT 1000 outside the full cleanup.
	$cutoff = microtime( true ) - ( 30.0 * DAY_IN_SECONDS );
	$ms_batch = uatr_bench_time(
		function () use ( $wpdb, $table, $cutoff ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark harness.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_on < %f LIMIT 1000", $cutoff ) );
		}
	);
	uatr_bench_emit( 'retention', (string) $rows_before, 'single_batch_delete', number_format( $ms_batch, 2, '.', '' ) );

	// Scenario (ii): full run_cleanup() pass (up to the per-run cap).
	$plugin   = UATR_Plugin::get_instance();
	$ms_full  = uatr_bench_time(
		function () use ( $plugin ) {
			$plugin->run_cleanup();
		}
	);
	$rows_after = uatr_bench_row_count();
	$deleted    = $rows_before - $rows_after - 1000; // subtract the single-batch DELETE above
	uatr_bench_emit( 'retention', (string) $rows_before, 'full_cleanup_pass', number_format( $ms_full, 2, '.', '' ) );
	uatr_bench_emit( 'retention', (string) $rows_before, 'full_cleanup_rows_deleted', (string) max( 0, $deleted ) );

	// Restore.
	if ( false !== $original_retention ) {
		update_option( 'uatr_retention_days', $original_retention );
	}
}

/**
 * Scenario (iv): two workers racing on the atomic claim.
 *
 * Launches a child PHP process that attempts to acquire the lock at the same
 * time as the parent. Exactly one should succeed and run cleanup; the other
 * should return 0 rows deleted and leave early.
 *
 * Verifies: add_option() is atomic enough that only one worker ever enters
 * the cleanup body under simultaneous invocation.
 */
function uatr_bench_concurrent() {
	// Clear any residual lock from a prior run.
	delete_option( UATR_Plugin::CLEANUP_LOCK_OPTION );

	$worker_script = escapeshellarg( __DIR__ . '/concurrent-worker.php' );
	$php           = escapeshellarg( PHP_BINARY );
	$cmd           = $php . ' ' . $worker_script . ' 2>/dev/null';

	// Launch two worker processes back-to-back and collect their PIDs.
	$descriptors = array(
		1 => array( 'pipe', 'w' ),
	);

	$procs   = array();
	$outputs = array();
	for ( $i = 0; $i < 2; $i++ ) {
		$pipes      = array();
		$procs[]    = proc_open( $cmd, $descriptors, $pipes );
		$outputs[]  = $pipes[1];
	}

	$results = array();
	foreach ( $outputs as $i => $pipe ) {
		$results[ $i ] = trim( stream_get_contents( $pipe ) );
		fclose( $pipe );
		proc_close( $procs[ $i ] );
	}

	// Each worker prints either "CLAIMED <uuid>" or "REJECTED".
	$claimed  = 0;
	$rejected = 0;
	foreach ( $results as $line ) {
		if ( 0 === strpos( $line, 'CLAIMED' ) ) {
			++$claimed;
		} elseif ( 0 === strpos( $line, 'REJECTED' ) ) {
			++$rejected;
		}
	}

	uatr_bench_emit( 'concurrent', '-', 'first_worker_claimed', $claimed >= 1 ? 'yes' : 'no' );
	uatr_bench_emit( 'concurrent', '-', 'second_worker_rejected', $rejected >= 1 ? 'yes' : 'no' );
	uatr_bench_emit( 'concurrent', '-', 'exactly_one_winner', ( 1 === $claimed && 1 === $rejected ) ? 'yes' : 'no' );
}

/**
 * Scenario (v): stale-lock recovery.
 *
 * Plant a lock with a timestamp older than CLEANUP_LOCK_STALE_AFTER and
 * verify that run_cleanup() reclaims it and proceeds.
 */
function uatr_bench_stale_lock() {
	// Plant a stale lock: timestamp 20 min in the past.
	$stale_timestamp = time() - ( 20 * MINUTE_IN_SECONDS );
	$stale_owner     = 'ghost-' . wp_generate_uuid4();
	delete_option( UATR_Plugin::CLEANUP_LOCK_OPTION );
	add_option( UATR_Plugin::CLEANUP_LOCK_OPTION, $stale_owner . '|' . $stale_timestamp, '', 'no' );

	$before = get_option( UATR_Plugin::CLEANUP_LOCK_OPTION );
	$planted_correctly = is_string( $before ) && 0 === strpos( $before, $stale_owner );
	uatr_bench_emit( 'stale-lock', '-', 'stale_lock_planted', $planted_correctly ? 'yes' : 'no' );

	// Trigger a cleanup. With retention_days set to something that matches no
	// rows (e.g. 365000 days), run_cleanup() still has to go through the
	// claim path — which is the thing under test.
	$original_retention = get_option( 'uatr_retention_days' );
	update_option( 'uatr_retention_days', 365000 );

	$plugin = UATR_Plugin::get_instance();
	$plugin->run_cleanup();

	// After the call, the lock should be released (our worker released its
	// own claim). Or at minimum, the stale owner should be gone.
	$after             = get_option( UATR_Plugin::CLEANUP_LOCK_OPTION );
	$stale_owner_gone  = ( false === $after ) || ( is_string( $after ) && 0 !== strpos( $after, $stale_owner ) );
	uatr_bench_emit( 'stale-lock', '-', 'reclaimed_after_stale', $stale_owner_gone ? 'yes' : 'no' );

	// Restore.
	if ( false !== $original_retention ) {
		update_option( 'uatr_retention_days', $original_retention );
	}
	delete_option( UATR_Plugin::CLEANUP_LOCK_OPTION );
}

// Header.
echo "scenario\trows\tmetric\tvalue\n";

switch ( $scenario ) {
	case 'stats':
		uatr_bench_stats( $wpdb, $table );
		break;
	case 'retention':
		uatr_bench_retention( $wpdb, $table );
		break;
	case 'concurrent':
		uatr_bench_concurrent();
		break;
	case 'stale-lock':
		uatr_bench_stale_lock();
		break;
	case 'all':
		uatr_bench_stats( $wpdb, $table );
		uatr_bench_retention( $wpdb, $table );
		uatr_bench_concurrent();
		uatr_bench_stale_lock();
		break;
	default:
		fwrite( STDERR, "Unknown scenario: {$scenario}\n" );
		fwrite( STDERR, "Valid: stats, retention, concurrent, stale-lock, all\n" );
		exit( 1 );
}
