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
	// Issue #30: the worker pool ceiling and every relevant timeout, read
	// back from the running stack.
	'pool_max_children'                 => 6,
	'max_execution_time_seconds'        => 30,
	'request_terminate_timeout_seconds' => 35,
	'fastcgi_read_timeout_seconds'      => 40,
	// Issue #31: the live WordPress/Action Scheduler versions and what
	// docker/composer.lock resolved.
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

// Issue #30: all four config facts are carried through into the snapshot
// verbatim -- this is the "read back into the preflight snapshot, and
// therefore into every result record" acceptance criterion, pinned down at
// the pure-logic layer.
wpcas_test_assert_same( 'healthy state: snapshot pool_max_children', 6, $result['snapshot']['pool_max_children'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot max_execution_time_seconds', 30, $result['snapshot']['max_execution_time_seconds'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot request_terminate_timeout_seconds', 35, $result['snapshot']['request_terminate_timeout_seconds'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot fastcgi_read_timeout_seconds', 40, $result['snapshot']['fastcgi_read_timeout_seconds'], $failures );

// Issue #30: a config fact that couldn't be read back from the running
// stack (null -- see wpcas_probe_server_config() in
// docker/wp-cli/lib/probe.php for when this happens) fails preflight
// explicitly, the same "loud, not silent" discipline this file already
// applies to the original four facts -- a null here is exactly the kind of
// unattributable occupancy figure this ticket exists to rule out.
$result = wpcas_preflight_evaluate( array_merge( $good_facts, array( 'pool_max_children' => null ) ) );
wpcas_test_assert_same( 'unreadable pool_max_children: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'unreadable pool_max_children: failure count', 1, count( $result['failures'] ), $failures );

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
// Config facts (issue #30) are left at their healthy values here on
// purpose, so this stays a test of the original four checks specifically
// -- see the "all eight broken" case below for every check failing at once.
$result = wpcas_preflight_evaluate(
	array_merge(
		array(
			'pending_count'     => 0,
			'callback_attached' => false,
			'cron_in_progress'  => true,
			'claims_count'      => 3,
		),
		array(
			'pool_max_children'                 => $good_facts['pool_max_children'],
			'max_execution_time_seconds'        => $good_facts['max_execution_time_seconds'],
			'request_terminate_timeout_seconds' => $good_facts['request_terminate_timeout_seconds'],
			'fastcgi_read_timeout_seconds'      => $good_facts['fastcgi_read_timeout_seconds'],
		),
		array(
			'wp_version'                        => $good_facts['wp_version'],
			'wp_version_lockfile'               => $good_facts['wp_version_lockfile'],
			'action_scheduler_version'          => $good_facts['action_scheduler_version'],
			'action_scheduler_version_lockfile' => $good_facts['action_scheduler_version_lockfile'],
		)
	)
);
wpcas_test_assert_same( 'all broken: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'all broken: failure count', 4, count( $result['failures'] ), $failures );

// Issue #30: every check failing at once (the original four, plus all four
// config facts unreadable) reports all eight failures, not just the first
// one found -- same "loud, full picture" discipline extended to the new
// checks. Version facts (issue #31) are left at their healthy values here on
// purpose, so this stays a test of the original four plus the config checks
// specifically.
$result = wpcas_preflight_evaluate(
	array_merge(
		array(
			'pending_count'                     => 0,
			'callback_attached'                 => false,
			'cron_in_progress'                  => true,
			'claims_count'                      => 3,
			'pool_max_children'                 => null,
			'max_execution_time_seconds'        => null,
			'request_terminate_timeout_seconds' => null,
			'fastcgi_read_timeout_seconds'      => null,
		),
		array(
			'wp_version'                        => $good_facts['wp_version'],
			'wp_version_lockfile'               => $good_facts['wp_version_lockfile'],
			'action_scheduler_version'          => $good_facts['action_scheduler_version'],
			'action_scheduler_version_lockfile' => $good_facts['action_scheduler_version_lockfile'],
		)
	)
);
wpcas_test_assert_same( 'all eight broken: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'all eight broken: failure count', 8, count( $result['failures'] ), $failures );

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
// four-assertion case above, now extended to the version check. Config
// facts (issue #30) are left at their healthy values here on purpose, so
// this stays a test of the original four plus the version checks
// specifically.
$result = wpcas_preflight_evaluate(
	array_merge(
		array(
			'pending_count'                     => 0,
			'callback_attached'                 => false,
			'cron_in_progress'                  => true,
			'claims_count'                      => 3,
			'wp_version'                        => '7.0.1',
			'wp_version_lockfile'               => '7.0.2',
			'action_scheduler_version'          => '3.9.2',
			'action_scheduler_version_lockfile' => '4.0.0',
		),
		array(
			'pool_max_children'                 => $good_facts['pool_max_children'],
			'max_execution_time_seconds'        => $good_facts['max_execution_time_seconds'],
			'request_terminate_timeout_seconds' => $good_facts['request_terminate_timeout_seconds'],
			'fastcgi_read_timeout_seconds'      => $good_facts['fastcgi_read_timeout_seconds'],
		)
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
