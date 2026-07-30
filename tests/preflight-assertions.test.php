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
	'pending_count'     => 50,
	'callback_attached' => true,
	'cron_in_progress'  => false,
	'claims_count'      => 0,
);

// A fully healthy state passes with no failures reported.
$result = wpcas_preflight_evaluate( $good_facts );
wpcas_test_assert_same( 'healthy state: ok', true, $result['ok'], $failures );
wpcas_test_assert_same( 'healthy state: failures', array(), $result['failures'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot ok', true, $result['snapshot']['ok'], $failures );
wpcas_test_assert_same( 'healthy state: snapshot pending_count', 50, $result['snapshot']['pending_count'], $failures );

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
		'pending_count'     => 0,
		'callback_attached' => false,
		'cron_in_progress'  => true,
		'claims_count'      => 3,
	)
);
wpcas_test_assert_same( 'all broken: ok', false, $result['ok'], $failures );
wpcas_test_assert_same( 'all broken: failure count', 4, count( $result['failures'] ), $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (" . 'wpcas_preflight_evaluate' . ")\n";
exit( 0 );
