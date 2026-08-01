<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/preflight-assertions.php.
 *
 * No WordPress, no container, no test framework -- this file is the
 * whole point of splitting wpcas_preflight_evaluate() out of the
 * WP-CLI-glue code in docker/wp-cli/lib/probe.php: it's pure input ->
 * output logic, so it's cheap to pin down with plain `assert()` calls
 * and `php tests/preflight-assertions.test.php`.
 *
 * Run: php tests/preflight-assertions.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/preflight-assertions.php';

$failures = array();

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function wpcas_test_assert_same( string $label, $expected, $actual, array &$failures ): void {
	if ( $expected !== $actual ) {
		$failures[] = sprintf(
			'%s: expected %s, got %s',
			$label,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
	}
}

$good_facts = array(
	'pending_count'                     => 50,
	'callback_attached'                 => true,
	'cron_in_progress'                  => false,
	'claims_count'                      => 0,
	'wp_version'                        => '7.0.2',
	'wp_version_lockfile'               => '7.0.2',
	'action_scheduler_version'          => '4.0.0',
	'action_scheduler_version_lockfile' => '4.0.0',
);

// A fully healthy state passes with no failures reported.
$result = wpcas_preflight_evaluate( $good_facts );
wpcas_test_assert_same( 'healthy state: ok', true, $result['ok'], $failures );
wpcas_test_assert_same( 'healthy state: failures', array(), $result['failures'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot ok', true, $result['snapshot']['ok'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot pending_count', 50, $result['snapshot']['pending_count'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot wp_version', '7.0.2', $result['snapshot']['wp_version'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot action_scheduler_version', '4.0.0', $result['snapshot']['action_scheduler_version'], $failures );

// A zero pending count fails -- this is the "instant no-op" false result
// the ticket is centrally worried about: nothing ran, but the count is
// also zero because nothing was ever seeded, which must not read as "ok".
$result = wpcas_preflight_evaluate( array_merge( $good_facts, array( 'pending_count' => 0 ) ) );
wpcas_test_assert_same( 'zero pending: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'zero pending: failure count', 1, count( $result['failures'] ), $failures );

// No callback attached fails -- the exact false-result class this ticket
// exists to make impossible to produce by accident.
$result = wpcas_preflight_evaluate( array_merge( $good_facts, array( 'callback_attached' => false ) ) );
wpcas_test_assert_same( 'no callback: ok', false, $result['ok'], $failures );

// A stuck cron-in-progress transient fails.
$result = wpcas_preflight_evaluate( array_merge( $good_facts, array( 'cron_in_progress' => true ) ) );
wpcas_test_assert_same( 'cron in progress: ok', false, $result['ok'], $failures );

// A non-empty claims table fails.
$result = wpcas_preflight_evaluate( array_merge( $good_facts, array( 'claims_count' => 3 ) ) );
wpcas_test_assert_same( 'dirty claims: ok', false, $result['ok'], $failures );

// Every assertion is independent -- a state failing all four reports all
// four, not just the first one found. Preflight is supposed to abort
// loudly with the full picture, not make the caller re-run it four times.
$result = wpcas_preflight_evaluate(
	array(
		'pending_count'                     => 0,
		'callback_attached'                 => false,
		'cron_in_progress'                  => true,
		'claims_count'                      => 3,
		'wp_version'                        => '7.0.2',
		'wp_version_lockfile'               => '7.0.2',
		'action_scheduler_version'          => '4.0.0',
		'action_scheduler_version_lockfile' => '4.0.0',
	)
);
wpcas_test_assert_same( 'all broken: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'all broken: failure count', 4, count( $result['failures'] ), $failures );

// Issue #31: a WordPress version mismatch (live site vs. what
// docker/composer.lock resolved) fails preflight, and the failure names
// both the live and lockfile-resolved values so the mismatch is
// diagnosable from the message alone.
$result = wpcas_preflight_evaluate( array_merge( $good_facts, array( 'wp_version' => '7.0.1' ) ) );
wpcas_test_assert_same( 'wp version mismatch: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'wp version mismatch: failure count', 1, count( $result['failures'] ), $failures );
if ( false === strpos( $result['failures'][0], '7.0.1' ) || false === strpos( $result['failures'][0], '7.0.2' ) ) {
	$failures[] = 'wp version mismatch: failure message does not name both the live and lockfile-resolved versions: ' . $result['failures'][0];
}
if ( false === stripos( $result['failures'][0], 'WordPress' ) ) {
	$failures[] = 'wp version mismatch: failure message does not name WordPress: ' . $result['failures'][0];
}
if ( false === stripos( $result['failures'][0], 'ADR-0002' ) && false === stripos( $result['failures'][0], 'volume' ) ) {
	$failures[] = 'wp version mismatch: failure message does not state a remedy: ' . $result['failures'][0];
}

// Same guarantee for the Action Scheduler version.
$result = wpcas_preflight_evaluate( array_merge( $good_facts, array( 'action_scheduler_version' => '3.9.2' ) ) );
wpcas_test_assert_same( 'action scheduler version mismatch: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'action scheduler version mismatch: failure count', 1, count( $result['failures'] ), $failures );
if ( false === strpos( $result['failures'][0], '3.9.2' ) || false === strpos( $result['failures'][0], '4.0.0' ) ) {
	$failures[] = 'action scheduler version mismatch: failure message does not name both the live and lockfile-resolved versions: ' . $result['failures'][0];
}
if ( false === stripos( $result['failures'][0], 'Action Scheduler' ) ) {
	$failures[] = 'action scheduler version mismatch: failure message does not name Action Scheduler: ' . $result['failures'][0];
}

// Both version facts land in the snapshot -- and therefore in every
// result record built from it (docker/wp-cli/lib/result-record.php) --
// regardless of whether preflight passed or failed.
$result = wpcas_preflight_evaluate( array_merge( $good_facts, array( 'wp_version' => '7.0.1' ) ) );
wpcas_test_assert_same( 'mismatch: snapshot wp_version', '7.0.1', $result['snapshot']['wp_version'], $failures );
wpcas_test_assert_same( 'mismatch: snapshot wp_version_lockfile', '7.0.2', $result['snapshot']['wp_version_lockfile'], $failures );
wpcas_test_assert_same( 'mismatch: snapshot action_scheduler_version', '4.0.0', $result['snapshot']['action_scheduler_version'], $failures );
wpcas_test_assert_same( 'mismatch: snapshot action_scheduler_version_lockfile', '4.0.0', $result['snapshot']['action_scheduler_version_lockfile'], $failures );

// A state failing every assertion, old and new alike, reports all six --
// same "no re-running to find the next failure" guarantee as the
// four-assertion case above, now extended to the version check.
$result = wpcas_preflight_evaluate(
	array(
		'pending_count'                     => 0,
		'callback_attached'                 => false,
		'cron_in_progress'                  => true,
		'claims_count'                      => 3,
		'wp_version'                        => '7.0.1',
		'wp_version_lockfile'               => '7.0.2',
		'action_scheduler_version'          => '3.9.2',
		'action_scheduler_version_lockfile' => '4.0.0',
	)
);
wpcas_test_assert_same( 'all broken including versions: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'all broken including versions: failure count', 6, count( $result['failures'] ), $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (" . 'wpcas_preflight_evaluate' . ")\n";
exit( 0 );
