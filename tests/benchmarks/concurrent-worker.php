<?php
/**
 * UATR benchmark harness — single-shot concurrency worker.
 *
 * Attempts to run a cleanup pass once and prints the outcome to stdout.
 * Used by the concurrent scenario in run.php, which spawns two of these
 * back-to-back to verify the atomic claim behavior.
 *
 * Prints exactly one of:
 *   CLAIMED <uuid> <rows_deleted>
 *   REJECTED
 *
 * @package Unstoppable_Activity_Tracker
 */

require __DIR__ . '/bootstrap.php';

$plugin = UATR_Plugin::get_instance();

// Access the private claim method via reflection — this is a test harness,
// not production code.
$ref        = new ReflectionClass( 'UATR_Plugin' );
$claim      = $ref->getMethod( 'claim_cleanup_lock' );
$release    = $ref->getMethod( 'release_cleanup_lock' );
$claim->setAccessible( true );
$release->setAccessible( true );

$owner = $claim->invoke( $plugin );
if ( null === $owner ) {
	echo "REJECTED\n";
	exit( 0 );
}

// Hold the lock briefly so the sibling worker sees contention, then release.
usleep( 100000 ); // 100ms.
$release->invoke( $plugin, $owner );
echo 'CLAIMED ' . $owner . "\n";
