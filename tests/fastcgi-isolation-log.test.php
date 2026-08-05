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

// --- wpcas_fastcgi_isolation_extract_last_entry() (issue #35) -------------
//
// The masked case as the three facts it really is: the file asked for 403,
// PHP refused (`false`), and the status actually in effect afterwards was
// still 200. Before #35 this line carried a hardcoded 403 as its
// post_flush_status, which read as an observation and was not one.
$masked_entry_line = json_encode(
	array(
		'file'              => 'flush-then-status',
		'attempted_status'  => 403,
		'set_call_returned' => false,
		'post_flush_status' => 200,
		'timestamp'         => '2026-08-04T17:00:00+00:00',
		'pid'               => 42,
	)
);

$masked_entry = wpcas_fastcgi_isolation_extract_last_entry( $masked_entry_line, 'flush-then-status' );
wpcas_test_assert_same( 'masked entry: attempted_status', 403, $masked_entry['attempted_status'], $failures );
wpcas_test_assert_same( 'masked entry: set_call_returned is false (PHP refused it)', false, $masked_entry['set_call_returned'], $failures );
wpcas_test_assert_same( 'masked entry: post_flush_status read back as 200', 200, $masked_entry['post_flush_status'], $failures );

// The control: same attempt, nothing flushed yet, so it takes effect.
$control_entry_line = json_encode(
	array(
		'file'              => 'status-then-flush',
		'attempted_status'  => 403,
		'set_call_returned' => 200,
		'post_flush_status' => 403,
		'timestamp'         => '2026-08-04T17:00:01+00:00',
		'pid'               => 43,
	)
);

$control_entry = wpcas_fastcgi_isolation_extract_last_entry( $control_entry_line, 'status-then-flush' );
wpcas_test_assert_same( 'control entry: set_call_returned is the previous code', 200, $control_entry['set_call_returned'], $failures );
wpcas_test_assert_same( 'control entry: post_flush_status is the 403 it set', 403, $control_entry['post_flush_status'], $failures );

// A line written by a build predating #35 carries neither new field: its
// status still parses, and the two absent facts report null rather than
// making the whole line unparseable.
$legacy_entry = wpcas_fastcgi_isolation_extract_last_entry(
	json_encode( array( 'file' => 'flush-then-status', 'post_flush_status' => 403 ) ),
	'flush-then-status'
);
wpcas_test_assert_same( 'legacy line: post_flush_status still parsed', 403, $legacy_entry['post_flush_status'], $failures );
wpcas_test_assert_same( 'legacy line: attempted_status is null, not fabricated', null, $legacy_entry['attempted_status'], $failures );
wpcas_test_assert_same( 'legacy line: set_call_returned is null, not fabricated', null, $legacy_entry['set_call_returned'], $failures );

wpcas_test_assert_same( 'no matching line: entry is null', null, wpcas_fastcgi_isolation_extract_last_entry( '', 'flush-then-status' ), $failures );

// --- wpcas_fastcgi_isolation_count_entries() (issue #35) ------------------
//
// What makes the measurement script's bounded wait honest: an append-only
// log outlives a run, so "an entry exists" is not the same question as
// "this request wrote one".
wpcas_test_assert_same( 'count: empty log', 0, wpcas_fastcgi_isolation_count_entries( '', 'flush-then-status' ), $failures );
wpcas_test_assert_same( 'count: one matching entry', 1, wpcas_fastcgi_isolation_count_entries( $masked_entry_line, 'flush-then-status' ), $failures );
wpcas_test_assert_same(
	'count: two runs of the same file',
	2,
	wpcas_fastcgi_isolation_count_entries( $masked_entry_line . "\n" . $masked_entry_line, 'flush-then-status' ),
	$failures
);
wpcas_test_assert_same(
	'count: other files and malformed lines are not counted',
	1,
	wpcas_fastcgi_isolation_count_entries( $masked_entry_line . "\n" . $control_entry_line . "\nnot json\n", 'flush-then-status' ),
	$failures
);

echo "OK (wpcas_fastcgi_isolation_extract_last_status / wpcas_fastcgi_isolation_extract_last_entry / wpcas_fastcgi_isolation_count_entries)\n";
exit( 0 );
