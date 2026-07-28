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
wpcas_test_assert_same( 'contexts: other count', 1, count( $summary['other'] ), $failures );
wpcas_test_assert_same( 'contexts: other verbatim', 'some unrelated action-scheduler log line', $summary['other'][0], $failures );

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
	'control'           => 'wp-cron',
	'command_argv'      => 'wp cron event run --due-now',
	'command_exit_code' => 0,
	'http_status'       => null,
	'started_at'        => '2026-07-28T18:00:00+00:00',
	'finished_at'       => '2026-07-28T18:00:11+00:00',
	'elapsed_seconds'   => 11.25,
	'preflight'         => array( 'ok' => true, 'pending_count' => 50 ),
	'pending_before'    => 50,
	'pending_after'     => 0,
	'log_messages'      => array( 'action started via WP Cron', 'action complete via WP Cron' ),
	'probe_records'     => array( array( 'sapi' => 'cli', 'pid' => 123, 'timestamp' => 1.0 ) ),
);

$record = wpcas_result_record_build( $facts );

wpcas_test_assert_same( 'record: schema_version', 1, $record['schema_version'], $failures );
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

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (wpcas_result_record_build / wpcas_result_compute_outcome / wpcas_result_summarize_execution_contexts)\n";
exit( 0 );
