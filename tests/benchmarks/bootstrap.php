<?php
/**
 * UATR benchmark harness — WordPress bootstrap.
 *
 * Walks up from this directory looking for wp-load.php, or accepts an
 * override via --wp-load=/path/to/wp-load.php. CLI-only.
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script is CLI-only.\n" );
	exit( 1 );
}

/**
 * Parse --flag=value args into an associative array.
 *
 * @param array $argv CLI args.
 * @return array
 */
function uatr_bench_parse_args( array $argv ) {
	$out = array();
	foreach ( $argv as $arg ) {
		if ( 0 === strpos( $arg, '--' ) && false !== strpos( $arg, '=' ) ) {
			list( $k, $v )          = explode( '=', substr( $arg, 2 ), 2 );
			$out[ $k ]              = $v;
		}
	}
	return $out;
}

$uatr_bench_args = uatr_bench_parse_args( $argv );

// Resolve wp-load.php.
$wp_load = isset( $uatr_bench_args['wp-load'] ) ? $uatr_bench_args['wp-load'] : null;

if ( null === $wp_load ) {
	// Walk up from this file looking for wp-load.php.
	$dir = __DIR__;
	for ( $i = 0; $i < 10; $i++ ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			$wp_load = $dir . '/wp-load.php';
			break;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}
}

if ( null === $wp_load || ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "Could not locate wp-load.php. Pass --wp-load=/absolute/path/to/wp-load.php\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
require $wp_load;

if ( ! class_exists( 'UATR_Plugin' ) ) {
	fwrite( STDERR, "UATR_Plugin class not found. Is the Unstoppable Activity Tracker plugin activated?\n" );
	exit( 1 );
}

if ( ! UATR_Installer::table_exists() ) {
	fwrite( STDERR, "UATR events table does not exist. Activate the plugin first.\n" );
	exit( 1 );
}

/**
 * Print a tab-separated measurement line.
 *
 * @param string $scenario Scenario name.
 * @param string $rows     Row count or '-' when n/a.
 * @param string $metric   Metric name.
 * @param string $value    Numeric ms or boolean yes/no.
 */
function uatr_bench_emit( $scenario, $rows, $metric, $value ) {
	echo $scenario . "\t" . $rows . "\t" . $metric . "\t" . $value . "\n";
}

/**
 * High-resolution millisecond timer.
 *
 * @param callable $fn Thunk to measure.
 * @return float Elapsed ms.
 */
function uatr_bench_time( callable $fn ) {
	$t0 = microtime( true );
	$fn();
	return ( microtime( true ) - $t0 ) * 1000.0;
}

/**
 * Current row count in the events table.
 *
 * @return int
 */
function uatr_bench_row_count() {
	global $wpdb;
	$table = UATR_Installer::get_table_name();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark harness, not shipped.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}
