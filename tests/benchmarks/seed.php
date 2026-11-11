<?php
/**
 * UATR benchmark harness — synthetic event seeder.
 *
 * Generates N representative events spread evenly across the last 365 days.
 * Writes in batches of 500 via a single multi-row INSERT so seeding 1M rows
 * takes seconds, not hours.
 *
 * Usage:
 *   php tests/benchmarks/seed.php --rows=1000000
 *   php tests/benchmarks/seed.php --rows=100000 --truncate
 *
 * @package Unstoppable_Activity_Tracker
 */

require __DIR__ . '/bootstrap.php';

$rows     = isset( $uatr_bench_args['rows'] ) ? max( 1, (int) $uatr_bench_args['rows'] ) : 100000;
$truncate = isset( $uatr_bench_args['truncate'] );

global $wpdb;
$table = UATR_Installer::get_table_name();

if ( $truncate ) {
	fwrite( STDERR, "Truncating {$table}...\n" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Benchmark harness.
	$wpdb->query( "TRUNCATE TABLE {$table}" );
}

$start = uatr_bench_row_count();
fwrite( STDERR, "Starting row count: {$start}\n" );
fwrite( STDERR, "Seeding {$rows} synthetic events...\n" );

// Representative mix of events by object/type.
$templates = array(
	array( 'object' => 'auth',      'event_type' => 'login',           'severity' => 'info',     'event_code' => 1001, 'message' => 'User logged in' ),
	array( 'object' => 'auth',      'event_type' => 'login_failed',    'severity' => 'warning',  'event_code' => 1002, 'message' => 'Failed login attempt' ),
	array( 'object' => 'auth',      'event_type' => 'logout',          'severity' => 'info',     'event_code' => 1003, 'message' => 'User logged out' ),
	array( 'object' => 'post',      'event_type' => 'updated',         'severity' => 'info',     'event_code' => 2002, 'message' => 'Post updated' ),
	array( 'object' => 'post',      'event_type' => 'published',       'severity' => 'info',     'event_code' => 2003, 'message' => 'Post published' ),
	array( 'object' => 'user',      'event_type' => 'profile_updated', 'severity' => 'info',     'event_code' => 3003, 'message' => 'User profile updated' ),
	array( 'object' => 'plugin',    'event_type' => 'activated',       'severity' => 'notice',   'event_code' => 4001, 'message' => 'Plugin activated' ),
	array( 'object' => 'plugin',    'event_type' => 'updated',         'severity' => 'notice',   'event_code' => 4004, 'message' => 'Plugin updated' ),
	array( 'object' => 'theme',     'event_type' => 'switched',        'severity' => 'warning',  'event_code' => 5001, 'message' => 'Theme switched' ),
	array( 'object' => 'core',      'event_type' => 'updated',         'severity' => 'warning',  'event_code' => 6001, 'message' => 'WordPress core updated' ),
	array( 'object' => 'setting',   'event_type' => 'option_changed',  'severity' => 'critical', 'event_code' => 6102, 'message' => 'Changed siteurl' ),
);

$batch_size   = 500;
$now          = microtime( true );
$window_start = $now - ( 365 * DAY_IN_SECONDS );
$batch_values = array();
$batch_params = array();
$written      = 0;

for ( $i = 0; $i < $rows; $i++ ) {
	$t              = $templates[ $i % count( $templates ) ];
	$created_on     = $window_start + ( ( $now - $window_start ) * ( $i / max( 1, $rows ) ) );
	$batch_values[] = '(%f, %d, %s, %s, %s, %d, %s, %s, %s, %s, %s)';
	array_push(
		$batch_params,
		$created_on,                              // created_on
		$t['event_code'],                         // event_code
		$t['object'],                             // object
		$t['event_type'],                         // event_type
		$t['severity'],                           // severity
		( $i % 5 ) + 1,                           // user_id
		( $i % 3 === 0 ) ? 'administrator' : 'editor', // user_role
		'127.0.0.' . ( ( $i % 254 ) + 1 ),        // ip
		'benchmark/1.0',                          // user_agent
		$t['message'] . ' #' . $i,                // message
		''                                        // meta (empty string instead of null)
	);

	if ( count( $batch_values ) >= $batch_size ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark harness, not shipped.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (created_on, event_code, object, event_type, severity, user_id, user_role, ip, user_agent, message, meta) VALUES " . implode( ', ', $batch_values ), $batch_params ) );
		$written     += count( $batch_values );
		$batch_values = array();
		$batch_params = array();
		if ( 0 === $written % 10000 ) {
			fwrite( STDERR, "  seeded {$written}...\n" );
		}
	}
}

if ( ! empty( $batch_values ) ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark harness, not shipped.
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (created_on, event_code, object, event_type, severity, user_id, user_role, ip, user_agent, message, meta) VALUES " . implode( ', ', $batch_values ), $batch_params ) );
	$written += count( $batch_values );
}

$end = uatr_bench_row_count();
fwrite( STDERR, "Done. Wrote {$written} rows. Final count: {$end}\n" );
