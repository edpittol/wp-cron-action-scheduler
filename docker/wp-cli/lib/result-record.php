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
 *
 * Record shape, decided now (issue #4) because this schema is canonical --
 * every later vector ticket in this series (#5/#6/#7) produces records
 * through this same file, and #10 renders all of them:
 *
 *   - CLI-control row (this ticket's two controls): `command` is
 *     `{argv, exit_code}`, `http_status` is `null` -- neither control makes
 *     an HTTP request.
 *   - HTTP-vector row (#5/#6/#7, not built here): `command` is `null`,
 *     `http_status` is whatever the endpoint returned.
 *
 * Both fields are always present in the record (never omitted), and both
 * are independently nullable -- a caller that genuinely has both a command
 * and an HTTP status for one vector (e.g. an HTTP endpoint that itself
 * shells out) is free to populate both; nothing here enforces exclusivity,
 * it's just what this ticket's two rows happen to look like.
 *
 * `canary_line` (added by issue #6, additive/backward-compatible): the
 * section-3 canary guard's own log line, when one fired -- see
 * docker/wp-cli/lib/canary.php for how it's parsed out of PHP's error log,
 * and docker/wp-cli/measure-async-ajax.php for how that's verified to be a
 * real, writable destination before being trusted. Optional in the input
 * facts (defaults to `null`) so this ticket's two CLI-control rows, which
 * never pass it, keep working unchanged; always present in the output,
 * same discipline as `http_status`/`command` -- a vector with no canary
 * guard armed reports `null`, not a missing key.
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
 * any other AS log line, e.g. "action created") is counted under 'other'
 * rather than silently dropped -- a discarded log line is exactly the kind
 * of quiet data loss this ticket's evidence pipeline exists to avoid.
 * Bucketed as a message -> count map, same shape as 'started'/'completed',
 * rather than a verbatim list: a normal 50-action run produces 50 identical
 * "action created" lines, and a count map carries that without repeating
 * the same string 50 times in the record.
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
			$other[ $message ] = ( $other[ $message ] ?? 0 ) + 1;
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
 * `command_argv`/`command_exit_code` are nullable together: pass both as
 * `null` for an HTTP-vector row that has no command at all (see the module
 * docblock above for the two row shapes this schema is designed to carry).
 * This ticket's own two CLI controls always pass both non-null.
 *
 * @param array{
 *     control: string,
 *     command_argv: string|null,
 *     command_exit_code: int|null,
 *     http_status: int|null,
 *     started_at: string,
 *     finished_at: string,
 *     elapsed_seconds: float,
 *     preflight: array<string, mixed>,
 *     pending_before: int,
 *     pending_after: int,
 *     log_messages: string[],
 *     probe_records: array<int, array{sapi: string, pid: int, timestamp: float}>,
 *     canary_line?: string|null,
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

	$command = null;
	if ( null !== $facts['command_argv'] ) {
		$command = array(
			'argv'      => $facts['command_argv'],
			'exit_code' => $facts['command_exit_code'],
		);
	}

	return array(
		// Bumped 1 -> 2 (issue #4 follow-up): `command` changed from
		// always-present to nullable, to accommodate the HTTP-vector row
		// shape #5/#6/#7 will produce (see the module docblock).
		'schema_version'      => 2,
		'control'             => $facts['control'],
		'command'             => $command,
		// Always present (never omitted), independent of `command` -- see
		// the module docblock for why both fields exist and when each is
		// populated.
		'http_status'         => $facts['http_status'],
		'started_at'          => $facts['started_at'],
		'finished_at'         => $facts['finished_at'],
		'elapsed_seconds'     => $facts['elapsed_seconds'],
		'preflight'           => $facts['preflight'],
		'outcome'             => $outcome,
		'execution_contexts'  => wpcas_result_summarize_execution_contexts( $facts['log_messages'] ),
		'probe_records'       => $facts['probe_records'],
		// Optional in the input facts (see the module docblock); always
		// present in the output, `null` when no canary fired/applies.
		'canary_line'         => $facts['canary_line'] ?? null,
	);
}
