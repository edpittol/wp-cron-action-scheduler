<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/canary.php.
 *
 * No WordPress, no container, no test framework -- same split as
 * tests/result-record.test.php and tests/preflight-assertions.test.php:
 * the pure parsing logic lives apart from the effectful log-reading glue
 * in docker/wp-cli/measure-async-ajax.php (#6) and
 * docker/wp-cli/measure-admin-page-load.php / measure-manual-run.php (#7)
 * so it's cheap to pin down with plain `assert()` calls.
 *
 * Run: php tests/canary.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/canary.php';

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

// --- wpcas_canary_extract_lines() ---------------------------------------

// The headline case: exactly one canary line, alone.
$one_line = '[cron-guard] queue run outside CLI: sapi=cli-server uri=/wp-admin/tools.php?page=action-scheduler ip=127.0.0.1';
wpcas_test_assert_same( 'single line: extracted', array( $one_line ), wpcas_canary_extract_lines( $one_line ), $failures );

// Empty/whitespace-only content: nothing fired, not an error.
wpcas_test_assert_same( 'empty string: no lines', array(), wpcas_canary_extract_lines( '' ), $failures );
wpcas_test_assert_same( 'whitespace-only: no lines', array(), wpcas_canary_extract_lines( "  \n\n  " ), $failures );

// Canary content mixed in with unrelated PHP error-log noise -- the
// extraction must pick out only the marked lines, in order, and leave
// everything else alone rather than treating "the log grew" as proof by
// itself.
$mixed = implode(
	"\n",
	array(
		'[28-Jul-2026 18:00:00 UTC] PHP Deprecated:  Something unrelated in some-plugin.php on line 12',
		$one_line,
		'[28-Jul-2026 18:00:01 UTC] PHP Warning:  Another unrelated line',
	)
);
wpcas_test_assert_same( 'mixed content: extracts only the canary line', array( $one_line ), wpcas_canary_extract_lines( $mixed ), $failures );

// Two canary lines (e.g. a chained async dispatch some guarded vector is
// expected to prevent, but the parser itself must not assume that) --
// both come back, in order.
$second_line = '[cron-guard] queue run outside CLI: sapi=cli-server uri=/wp-admin/index.php ip=10.0.0.5';
$two_lines   = $one_line . "\n" . $second_line;
wpcas_test_assert_same( 'two canary lines: both extracted, in order', array( $one_line, $second_line ), wpcas_canary_extract_lines( $two_lines ), $failures );

// A line that merely mentions "cron-guard" without the exact marker
// (e.g. a different guard's own diagnostic output) must not be picked up.
$unrelated_cron_guard_text = '[cron-guard] some other guard entirely, not this marker';
wpcas_test_assert_same( 'unrelated cron-guard text: not extracted', array(), wpcas_canary_extract_lines( $unrelated_cron_guard_text ), $failures );

// Issue #7's own headline negative case: the manual-run vector calls
// process_action() directly and never fires 'action_scheduler_before_process_queue'
// at all, so an empty log excerpt for that run is the expected, correct
// reading, not a parsing failure.
wpcas_test_assert_same( 'manual-run vector (no queue run): no lines', array(), wpcas_canary_extract_lines( '' ), $failures );

// --- wpcas_canary_join_lines() -------------------------------------------

wpcas_test_assert_same( 'join: no lines -> null', null, wpcas_canary_join_lines( array() ), $failures );
wpcas_test_assert_same( 'join: one line -> itself', $one_line, wpcas_canary_join_lines( array( $one_line ) ), $failures );
wpcas_test_assert_same( 'join: two lines -> newline-joined', $one_line . "\n" . $second_line, wpcas_canary_join_lines( array( $one_line, $second_line ) ), $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (wpcas_canary_extract_lines / wpcas_canary_join_lines)\n";
exit( 0 );
