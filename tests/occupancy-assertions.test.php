<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/occupancy-assertions.php.
 *
 * No WordPress, no container, no HTTP, no test framework -- same rationale
 * as tests/preflight-assertions.test.php: wpcas_occupancy_build_record() is
 * pure input -> output logic, so it's cheap to pin down with plain
 * `assert()` calls and `php tests/occupancy-assertions.test.php`.
 *
 * Run: php tests/occupancy-assertions.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/occupancy-assertions.php';

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

function wpcas_test_assert_true( string $label, bool $actual, array &$failures ): void {
	wpcas_test_assert_same( $label, true, $actual, $failures );
}

function wpcas_test_assert_false( string $label, bool $actual, array &$failures ): void {
	wpcas_test_assert_same( $label, false, $actual, $failures );
}

/**
 * A representative "healthy" set of facts: a fast trigger, a drain that
 * visibly drains over several seconds before completing, and front-end
 * latency that clearly degrades once concurrency (20) exceeds the
 * available workers (6 total, 1 tied up by the drain -- 5 free).
 */
function wpcas_test_healthy_facts(): array {
	return array(
		'server_model'  => 'php-cli-server',
		'workers_total' => 6,
		'trigger'       => array(
			'url'              => 'http://localhost:8080/wp-admin/index.php',
			'response_seconds' => 0.05,
			'http_code'        => 302,
		),
		'drain'         => array(
			'pending_before'  => 50,
			'timeout_seconds' => 30.0,
			'samples'         => array(
				array(
					't_offset_seconds' => 0.0,
					'pending'          => 50,
				),
				array(
					't_offset_seconds' => 3.0,
					'pending'          => 35,
				),
				array(
					't_offset_seconds' => 6.0,
					'pending'          => 18,
				),
				array(
					't_offset_seconds' => 10.0,
					'pending'          => 0,
				),
			),
		),
		'baseline_waves' => array(
			array(
				'concurrency'            => 3,
				'response_times_seconds' => array( 0.03, 0.03, 0.03 ),
			),
			array(
				'concurrency'            => 20,
				'response_times_seconds' => array_fill( 0, 20, 0.05 ),
			),
		),
		'drain_waves'   => array(
			array(
				't_offset_seconds'       => 1.0,
				'concurrency'            => 3,
				'response_times_seconds' => array( 0.03, 0.03, 0.03 ),
			),
			array(
				't_offset_seconds'       => 4.0,
				'concurrency'            => 20,
				'response_times_seconds' => array_fill( 0, 20, 0.15 ),
			),
			array(
				't_offset_seconds'       => 7.0,
				'concurrency'            => 20,
				'response_times_seconds' => array_fill( 0, 20, 0.16 ),
			),
		),
	);
}

// --- A healthy, degrading run is reported as "measured" with degradation. -
$result = wpcas_occupancy_build_record( wpcas_test_healthy_facts() );

wpcas_test_assert_same( 'healthy: result_kind', 'measured', $result['result_kind'], $failures );
wpcas_test_assert_same( 'healthy: server_model', 'php-cli-server, 6 workers', $result['server_model'], $failures );
wpcas_test_assert_same( 'healthy: drain_duration_seconds', 10.0, $result['drain_duration_seconds'], $failures );
wpcas_test_assert_true( 'healthy: drain_completed', $result['drain_completed'], $failures );
wpcas_test_assert_true( 'healthy: drain_observed_in_flight', $result['drain_observed_in_flight'], $failures );
wpcas_test_assert_true( 'healthy: degradation_observed', $result['degradation_observed'], $failures );
wpcas_test_assert_same( 'healthy: trigger response_seconds kept separate from drain duration', 0.05, $result['trigger']['response_seconds'], $failures );
wpcas_test_assert_true( 'healthy: trigger is_fast', $result['trigger']['is_fast'], $failures );
wpcas_test_assert_same( 'healthy: workers_occupied_by_drain', 1, $result['workers_occupied_by_drain'], $failures );
wpcas_test_assert_same( 'healthy: notes empty', array(), $result['notes'], $failures );

// concurrency=20 degraded from 0.05 baseline to a 0.155 average during the
// drain -- a >100% increase, comfortably over the 25% threshold.
$deg20 = $result['degradation_by_concurrency'][20];
if ( abs( $deg20['baseline_avg_seconds'] - 0.05 ) > 0.0001 ) {
	$failures[] = sprintf( 'healthy: concurrency=20 baseline avg: expected ~0.05, got %s', var_export( $deg20['baseline_avg_seconds'], true ) );
}
if ( abs( $deg20['during_drain_avg_seconds'] - 0.155 ) > 0.0001 ) {
	$failures[] = sprintf( 'healthy: concurrency=20 during-drain avg: expected ~0.155, got %s', var_export( $deg20['during_drain_avg_seconds'], true ) );
}

// --- A drain that never reaches zero pending is a negative result. --------
$never_completes = wpcas_test_healthy_facts();
$never_completes['drain']['samples'] = array(
	array(
		't_offset_seconds' => 0.0,
		'pending'          => 50,
	),
	array(
		't_offset_seconds' => 30.0,
		'pending'          => 12,
	),
);
$result = wpcas_occupancy_build_record( $never_completes );
wpcas_test_assert_same( 'never completes: result_kind', 'negative', $result['result_kind'], $failures );
wpcas_test_assert_same( 'never completes: drain_duration_seconds', null, $result['drain_duration_seconds'], $failures );
wpcas_test_assert_false( 'never completes: drain_completed', $result['drain_completed'], $failures );

// --- Pending falling to 0 without ever being caught mid-drain is negative -
// (e.g. a stale lock silently suppressed the trigger and "0 pending" just
// means the previous drain had already finished before this run started).
$never_in_flight = wpcas_test_healthy_facts();
$never_in_flight['drain']['samples'] = array(
	array(
		't_offset_seconds' => 0.0,
		'pending'          => 0,
	),
);
$result = wpcas_occupancy_build_record( $never_in_flight );
wpcas_test_assert_same( 'never in flight: result_kind', 'negative', $result['result_kind'], $failures );
wpcas_test_assert_true( 'never in flight: drain_completed', $result['drain_completed'], $failures );
wpcas_test_assert_false( 'never in flight: drain_observed_in_flight', $result['drain_observed_in_flight'], $failures );

// --- A completed, in-flight drain with no measurable degradation is still
// "measured" (not negative) -- absence of a large effect at the tested
// concurrency levels is itself a valid, reportable finding, not a failure
// to reproduce.
$no_degradation = wpcas_test_healthy_facts();
$no_degradation['drain_waves'][1]['response_times_seconds'] = array_fill( 0, 20, 0.051 );
$no_degradation['drain_waves'][2]['response_times_seconds'] = array_fill( 0, 20, 0.052 );
$result = wpcas_occupancy_build_record( $no_degradation );
wpcas_test_assert_same( 'no degradation: result_kind', 'measured', $result['result_kind'], $failures );
wpcas_test_assert_false( 'no degradation: degradation_observed', $result['degradation_observed'], $failures );
$note_count = count( $result['notes'] );
if ( 1 !== $note_count ) {
	$failures[] = sprintf( 'no degradation: expected exactly 1 note, got %d', $note_count );
}

// --- A slow trigger (not "almost instant") is flagged as not fast. --------
$slow_trigger = wpcas_test_healthy_facts();
$slow_trigger['trigger']['response_seconds'] = 4.0;
$result = wpcas_occupancy_build_record( $slow_trigger );
wpcas_test_assert_false( 'slow trigger: is_fast', $result['trigger']['is_fast'], $failures );
wpcas_test_assert_same( 'slow trigger: response_seconds unchanged', 4.0, $result['trigger']['response_seconds'], $failures );

// --- wpcas_occupancy_wave_stats() on an empty sample list. -----------------
$stats = wpcas_occupancy_wave_stats( array() );
wpcas_test_assert_same( 'empty wave: count', 0, $stats['count'], $failures );
wpcas_test_assert_same( 'empty wave: avg', 0.0, $stats['avg'], $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (" . 'wpcas_occupancy_build_record' . ")\n";
exit( 0 );
