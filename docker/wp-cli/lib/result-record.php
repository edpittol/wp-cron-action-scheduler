<?php

declare( strict_types=1 );

/**
 * Pure assembly/derivation logic for the result record (issue #4) -- the
 * unit of evidence every later ticket in this series produces. Deliberately
 * free of any WordPress/$wpdb/WP-CLI dependency, so it can be exercised
 * with plain `php`, no container or WP bootstrap required -- see
 * tests/result-record.test.php. All the WP-aware fact-gathering (querying
 * Action Scheduler's pending count and its own log table, reading the
 * probe's own execution log, running the actual CLI control) lives in
 * docker/wp-cli/measure.php and docker/wp-cli/lib/probe.php instead, which
 * call into this file. Same split as
 * docker/wp-cli/lib/preflight-assertions.php vs. docker/wp-cli/lib/probe.php.
 *
 * Central invariant this file exists to enforce: outcome is always derived
 * from work actually performed (pending count before/after, corroborated
 * by the probe's own independent execution log), never from a command's
 * exit code or an HTTP status. Status is recorded because it's interesting
 * -- e.g. to notice a control that "succeeded" by its own exit code while
 * draining nothing -- not because it's evidence of what happened.
 */

/**
 * Derives the outcome block from already-gathered facts about the queue's
 * state before and after a control ran, plus however many of the probe's
 * own execution-log rows were observed afterwards (an independent signal:
 * the mu-plugin callback writes one of these per actual invocation, so it
 * corroborates -- or contradicts -- the pending-count delta rather than
 * just restating it).
 *
 * Never takes a command exit code or HTTP status as input: that is the
 * point of this function's signature, not an oversight.
 */
function wpcas_result_compute_outcome( int $pending_before, int $pending_after, int $probe_executions_observed ): array {
	return array(
		'pending_before'            => $pending_before,
		'pending_after'             => $pending_after,
		'drained'                   => $pending_before - $pending_after,
		'fully_drained'             => 0 === $pending_after,
		'probe_executions_observed' => $probe_executions_observed,
	);
}

/**
 * Buckets a flat list of Action Scheduler log messages (as read from its
 * own `actionscheduler_logs` table -- see wpcas_probe_log_messages_for_actions()
 * in docker/wp-cli/lib/probe.php) by execution context.
 *
 * Action Scheduler's own logger (classes/abstracts/ActionScheduler_Logger.php
 * in the action-scheduler plugin) writes messages of the literal shape
 * "action started via WP Cron" / "action complete via WP Cron" (or "WP CLI"
 * for the CLI runner) -- the $context string is Action Scheduler's own,
 * not something this repo invents. Splitting started/completed lets a
 * caller notice a context that started actions but never completed them.
 * Anything that doesn't match that shape (failures, ignored actions, or
 * any other AS log line) is kept verbatim under 'other' rather than
 * silently dropped -- a discarded log line is exactly the kind of quiet
 * data loss this ticket's evidence pipeline exists to avoid.
 *
 * @param string[] $messages
 */
function wpcas_result_summarize_execution_contexts( array $messages ): array {
	$started   = array();
	$completed = array();
	$other     = array();

	foreach ( $messages as $message ) {
		if ( preg_match( '/^action started via (.+)$/', $message, $matches ) ) {
			$context             = $matches[1];
			$started[ $context ] = ( $started[ $context ] ?? 0 ) + 1;
		} elseif ( preg_match( '/^action complete via (.+)$/', $message, $matches ) ) {
			$context               = $matches[1];
			$completed[ $context ] = ( $completed[ $context ] ?? 0 ) + 1;
		} else {
			$other[] = $message;
		}
	}

	return array(
		'started'   => $started,
		'completed' => $completed,
		'other'     => $other,
	);
}

/**
 * Assembles the full result record from already-gathered facts. No
 * WordPress/$wpdb/WP-CLI calls here -- everything this function needs is
 * passed in.
 *
 * @param array{
 *     control: string,
 *     command_argv: string,
 *     command_exit_code: int,
 *     http_status: int|null,
 *     started_at: string,
 *     finished_at: string,
 *     elapsed_seconds: float,
 *     preflight: array<string, mixed>,
 *     pending_before: int,
 *     pending_after: int,
 *     log_messages: string[],
 *     probe_records: array<int, array{sapi: string, pid: int, timestamp: float}>,
 * } $facts
 *
 * @return array<string, mixed>
 */
function wpcas_result_record_build( array $facts ): array {
	$outcome = wpcas_result_compute_outcome(
		$facts['pending_before'],
		$facts['pending_after'],
		count( $facts['probe_records'] )
	);

	return array(
		'schema_version'      => 1,
		'control'             => $facts['control'],
		'command'             => array(
			'argv'      => $facts['command_argv'],
			'exit_code' => $facts['command_exit_code'],
		),
		// Neither CLI control in this ticket makes an HTTP request -- both
		// run entirely in-process via WP-CLI. Kept as an explicit, always-
		// present field (rather than omitted) so later, HTTP-triggered
		// scenarios can populate it without changing the record shape.
		'http_status'         => $facts['http_status'],
		'started_at'          => $facts['started_at'],
		'finished_at'         => $facts['finished_at'],
		'elapsed_seconds'     => $facts['elapsed_seconds'],
		'preflight'           => $facts['preflight'],
		'outcome'             => $outcome,
		'execution_contexts'  => wpcas_result_summarize_execution_contexts( $facts['log_messages'] ),
		'probe_records'       => $facts['probe_records'],
	);
}
