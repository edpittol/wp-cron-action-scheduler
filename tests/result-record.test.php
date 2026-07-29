<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/result-record.php.
 *
 * No WordPress, no container, no test framework -- same split as
 * tests/preflight-assertions.test.php: the pure assembly/derivation logic
 * lives apart from the WP-aware fact-gathering in docker/wp-cli/lib/probe.php
 * so it's cheap to pin down with plain `assert()` calls.
 *
 * Run: php tests/result-record.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/result-record.php';

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

// --- wpcas_result_compute_outcome() -----------------------------------

// The headline case: 50 seeded, 0 left pending, 50 independent probe
// executions observed -- everything agrees a full drain happened.
$outcome = wpcas_result_compute_outcome( 50, 0, 50 );
wpcas_test_assert_same( 'full drain: pending_before', 50, $outcome['pending_before'], $failures );
wpcas_test_assert_same( 'full drain: pending_after', 0, $outcome['pending_after'], $failures );
wpcas_test_assert_same( 'full drain: drained', 50, $outcome['drained'], $failures );
wpcas_test_assert_same( 'full drain: fully_drained', true, $outcome['fully_drained'], $failures );
wpcas_test_assert_same( 'full drain: probe_executions_observed', 50, $outcome['probe_executions_observed'], $failures );

// The negative-result case this ticket explicitly requires recording
// faithfully: a control that drains nothing must report drained=0 and
// fully_drained=false, not be rounded up or silently skipped.
$outcome = wpcas_result_compute_outcome( 50, 50, 0 );
wpcas_test_assert_same( 'no drain: drained', 0, $outcome['drained'], $failures );
wpcas_test_assert_same( 'no drain: fully_drained', false, $outcome['fully_drained'], $failures );

// A partial drain is reported exactly as observed.
$outcome = wpcas_result_compute_outcome( 50, 10, 40 );
wpcas_test_assert_same( 'partial drain: drained', 40, $outcome['drained'], $failures );
wpcas_test_assert_same( 'partial drain: fully_drained', false, $outcome['fully_drained'], $failures );

// --- wpcas_result_summarize_execution_contexts() ------------------------

$messages = array(
	'action started via WP Cron',
	'action started via WP Cron',
	'action complete via WP Cron',
	'action complete via WP Cron',
	'some unrelated action-scheduler log line',
);
$summary = wpcas_result_summarize_execution_contexts( $messages );
wpcas_test_assert_same( 'contexts: started WP Cron count', 2, $summary['started']['WP Cron'], $failures );
wpcas_test_assert_same( 'contexts: completed WP Cron count', 2, $summary['completed']['WP Cron'], $failures );
wpcas_test_assert_same( 'contexts: other is a count map, not a verbatim list', 1, count( $summary['other'] ), $failures );
wpcas_test_assert_same(
	'contexts: other message count',
	1,
	$summary['other']['some unrelated action-scheduler log line'],
	$failures
);

// A message repeated many times (the normal case: N identical "action
// created" lines for an N-action batch) is counted, not repeated N times
// in the record -- this is exactly why 'other' is a count map.
$repeated = array_fill( 0, 50, 'action created' );
$summary  = wpcas_result_summarize_execution_contexts( $repeated );
wpcas_test_assert_same( 'contexts: repeated other collapses to one key', 1, count( $summary['other'] ), $failures );
wpcas_test_assert_same( 'contexts: repeated other count', 50, $summary['other']['action created'], $failures );

// Mixed contexts in the same batch are split, not merged -- this is what
// makes the two controls distinguishable in a single record shape.
$mixed = array(
	'action started via WP Cron',
	'action started via WP CLI',
	'action complete via WP Cron',
	'action complete via WP CLI',
);
$summary = wpcas_result_summarize_execution_contexts( $mixed );
wpcas_test_assert_same( 'mixed contexts: started WP Cron', 1, $summary['started']['WP Cron'], $failures );
wpcas_test_assert_same( 'mixed contexts: started WP CLI', 1, $summary['started']['WP CLI'], $failures );
wpcas_test_assert_same( 'mixed contexts: completed WP Cron', 1, $summary['completed']['WP Cron'], $failures );
wpcas_test_assert_same( 'mixed contexts: completed WP CLI', 1, $summary['completed']['WP CLI'], $failures );

// An empty list summarizes to empty buckets, not an error.
$summary = wpcas_result_summarize_execution_contexts( array() );
wpcas_test_assert_same( 'empty contexts: started', array(), $summary['started'], $failures );
wpcas_test_assert_same( 'empty contexts: completed', array(), $summary['completed'], $failures );
wpcas_test_assert_same( 'empty contexts: other', array(), $summary['other'], $failures );

// --- wpcas_result_record_build() ---------------------------------------

$facts = array(
	'control'                 => 'wp-cron',
	'command_argv'            => 'wp cron event run --due-now',
	'command_exit_code'       => 0,
	'http_status'             => null,
	'started_at'              => '2026-07-28T18:00:00+00:00',
	'finished_at'             => '2026-07-28T18:00:11+00:00',
	'elapsed_seconds'         => 11.25,
	'preflight'               => array( 'ok' => true, 'pending_count' => 50 ),
	'pending_before'          => 50,
	'pending_after'           => 0,
	'log_messages'            => array( 'action started via WP Cron', 'action complete via WP Cron' ),
	'probe_records'           => array( array( 'sapi' => 'cli', 'pid' => 123, 'timestamp' => 1.0 ) ),
	'cron_in_progress_after'  => false,
);

$record = wpcas_result_record_build( $facts );

wpcas_test_assert_same( 'record: schema_version', 3, $record['schema_version'], $failures );
wpcas_test_assert_same( 'record: control', 'wp-cron', $record['control'], $failures );
wpcas_test_assert_same( 'record: command argv', 'wp cron event run --due-now', $record['command']['argv'], $failures );
wpcas_test_assert_same( 'record: command exit_code', 0, $record['command']['exit_code'], $failures );
wpcas_test_assert_same( 'record: http_status', null, $record['http_status'], $failures );
wpcas_test_assert_same( 'record: started_at', '2026-07-28T18:00:00+00:00', $record['started_at'], $failures );
wpcas_test_assert_same( 'record: elapsed_seconds', 11.25, $record['elapsed_seconds'], $failures );
wpcas_test_assert_same( 'record: preflight passthrough', $facts['preflight'], $record['preflight'], $failures );
wpcas_test_assert_same( 'record: outcome drained', 50, $record['outcome']['drained'], $failures );
wpcas_test_assert_same( 'record: outcome fully_drained', true, $record['outcome']['fully_drained'], $failures );
wpcas_test_assert_same( 'record: execution_contexts started WP Cron', 1, $record['execution_contexts']['started']['WP Cron'], $failures );
wpcas_test_assert_same( 'record: probe_records passthrough', $facts['probe_records'], $record['probe_records'], $failures );
wpcas_test_assert_same( 'record: cron_in_progress_after passthrough (false)', false, $record['cron_in_progress_after'], $failures );

// The exact acceptance criterion this field exists to make provable from
// the committed record alone (issue #5's armed HTTP-vector scenario):
// "no cron-in-progress transient left behind" must be readable without
// taking an uncommitted follow-up preflight on trust. A stray leftover
// transient (the false-result class this whole pipeline exists to catch)
// must come through as `true`, not silently coerced.
$facts_with_leftover_transient                            = $facts;
$facts_with_leftover_transient['cron_in_progress_after']  = true;
$record_with_leftover_transient                           = wpcas_result_record_build( $facts_with_leftover_transient );
wpcas_test_assert_same(
	'record: cron_in_progress_after passthrough (true)',
	true,
	$record_with_leftover_transient['cron_in_progress_after'],
	$failures
);

// The ticket's central invariant: outcome must be derived purely from
// pending_before/pending_after (and the probe's own independent
// corroborating log), never from the command's exit code. A record for a
// command that exited non-zero but still fully drained the queue (e.g. a
// WP-CLI deprecation warning tripping a non-zero exit while the run itself
// succeeded) must still report a full drain.
$facts_with_failing_exit_code            = $facts;
$facts_with_failing_exit_code['command_exit_code'] = 1;
$record_with_failing_exit_code           = wpcas_result_record_build( $facts_with_failing_exit_code );
wpcas_test_assert_same(
	'record: outcome ignores non-zero exit code',
	true,
	$record_with_failing_exit_code['outcome']['fully_drained'],
	$failures
);
wpcas_test_assert_same(
	'record: command exit_code is still recorded, just not used for outcome',
	1,
	$record_with_failing_exit_code['command']['exit_code'],
	$failures
);

// Conversely: a command that exits 0 but genuinely drained nothing must
// still be reported as a non-drain -- a "successful" exit code is not
// itself evidence of work performed.
$facts_zero_exit_no_drain                     = $facts;
$facts_zero_exit_no_drain['command_exit_code'] = 0;
$facts_zero_exit_no_drain['pending_after']     = 50;
$facts_zero_exit_no_drain['log_messages']      = array();
$facts_zero_exit_no_drain['probe_records']     = array();
$record_zero_exit_no_drain                     = wpcas_result_record_build( $facts_zero_exit_no_drain );
wpcas_test_assert_same( 'record: zero exit but no drain -- drained', 0, $record_zero_exit_no_drain['outcome']['drained'], $failures );
wpcas_test_assert_same( 'record: zero exit but no drain -- fully_drained', false, $record_zero_exit_no_drain['outcome']['fully_drained'], $failures );

// --- HTTP-vector row shape (issue #4 follow-up) -------------------------
//
// This ticket only ever builds CLI-control rows itself, but the schema is
// canonical for #5/#6/#7's HTTP-triggered vectors too: `command_argv` and
// `command_exit_code` both `null` in, `command` must come out `null` --
// not an object with null members -- while `http_status` is carried
// through untouched. Locking this down here so those tickets don't each
// have to work out the null-handling for themselves.
$http_vector_facts                     = $facts;
$http_vector_facts['command_argv']     = null;
$http_vector_facts['command_exit_code'] = null;
$http_vector_facts['http_status']      = 200;
$http_vector_facts['log_messages']     = array();
$http_vector_facts['probe_records']    = array();
$http_vector_record                    = wpcas_result_record_build( $http_vector_facts );
wpcas_test_assert_same( 'http-vector row: command is null, not an object', null, $http_vector_record['command'], $failures );
wpcas_test_assert_same( 'http-vector row: http_status carried through', 200, $http_vector_record['http_status'], $failures );
wpcas_test_assert_same( 'http-vector row: schema_version', 3, $http_vector_record['schema_version'], $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (wpcas_result_record_build / wpcas_result_compute_outcome / wpcas_result_summarize_execution_contexts)\n";
exit( 0 );
