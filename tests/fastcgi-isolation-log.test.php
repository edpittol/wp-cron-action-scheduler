<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/fastcgi-isolation-log.php.
 *
 * No WordPress, no container, no test framework -- same discipline as
 * tests/canary.test.php and tests/http-status.test.php: this is pure
 * input -> output parsing logic, so it's cheap to pin down with plain
 * `assert()`-style checks before wiring it into
 * docker/wp-cli/measure-fastcgi-isolation.php (issue #34), which cannot
 * itself be exercised without a running container.
 *
 * Run: php tests/fastcgi-isolation-log.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/fastcgi-isolation-log.php';

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

// A single, well-formed line for the file being asked about.
$flushed_line = json_encode(
	array(
		'file'              => 'flush-then-status',
		'post_flush_status' => 403,
		'timestamp'         => '2026-08-01T00:00:00+00:00',
		'pid'               => 123,
	)
);
wpcas_test_assert_same(
	'single matching line: extracts its post_flush_status',
	403,
	wpcas_fastcgi_isolation_extract_last_status( $flushed_line, 'flush-then-status' ),
	$failures
);

// Empty/whitespace-only content: nothing was ever recorded, not an error.
wpcas_test_assert_same( 'empty string: no status', null, wpcas_fastcgi_isolation_extract_last_status( '', 'flush-then-status' ), $failures );
wpcas_test_assert_same( 'whitespace-only: no status', null, wpcas_fastcgi_isolation_extract_last_status( "  \n\n  ", 'flush-then-status' ), $failures );

// A line for the *other* file must not be mistaken for this one's record.
$control_line = json_encode(
	array(
		'file'              => 'status-then-flush',
		'post_flush_status' => 403,
		'timestamp'         => '2026-08-01T00:00:01+00:00',
		'pid'               => 124,
	)
);
wpcas_test_assert_same(
	'other file only: no status for this key',
	null,
	wpcas_fastcgi_isolation_extract_last_status( $control_line, 'flush-then-status' ),
	$failures
);

// Both files' lines present, interleaved with a malformed line and a
// blank line -- extraction must pick out only the matching, well-formed
// entries and ignore the rest without erroring.
$mixed = implode(
	"\n",
	array(
		$control_line,
		'not even json',
		'',
		$flushed_line,
	)
);
wpcas_test_assert_same( 'mixed content: flush-then-status found', 403, wpcas_fastcgi_isolation_extract_last_status( $mixed, 'flush-then-status' ), $failures );
wpcas_test_assert_same( 'mixed content: status-then-flush found', 403, wpcas_fastcgi_isolation_extract_last_status( $mixed, 'status-then-flush' ), $failures );

// Two entries for the same key: "last wins" -- see the module docblock
// for why this is safe for this ticket's strictly-sequential requests.
$first_run  = json_encode(
	array(
		'file'              => 'flush-then-status',
		'post_flush_status' => 403,
		'timestamp'         => '2026-08-01T00:00:00+00:00',
		'pid'               => 100,
	)
);
$second_run = json_encode(
	array(
		'file'              => 'flush-then-status',
		'post_flush_status' => 403,
		'timestamp'         => '2026-08-01T00:05:00+00:00',
		'pid'               => 200,
	)
);
wpcas_test_assert_same(
	'two entries for the same key: last one wins',
	403,
	wpcas_fastcgi_isolation_extract_last_status( $first_run . "\n" . $second_run, 'flush-then-status' ),
	$failures
);

// A JSON line missing the fields this function needs is ignored, not
// fatal -- a malformed or unrelated log entry must not crash parsing.
$missing_fields = json_encode( array( 'file' => 'flush-then-status' ) );
wpcas_test_assert_same( 'line missing post_flush_status: no status', null, wpcas_fastcgi_isolation_extract_last_status( $missing_fields, 'flush-then-status' ), $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (wpcas_fastcgi_isolation_extract_last_status)\n";
exit( 0 );
