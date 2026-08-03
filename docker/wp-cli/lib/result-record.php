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
 * schema_version 2 -> 3 (issue #5): added `cron_in_progress_after`, the
 * boolean "doing_cron" transient state read immediately after the control
 * ran -- independent of, and gathered the same way as, the `cron_in_progress`
 * fact already present in every record's `preflight` snapshot (which is
 * the state *before* the control ran). Issue #5's armed HTTP-vector
 * scenario has an acceptance criterion of its own beyond the pending-count
 * delta and the probe's own execution log: "no cron-in-progress transient
 * left behind." Before this field existed, that criterion was only
 * provable by taking an uncommitted follow-up `bin/stack preflight` run on
 * trust -- exactly the kind of unverifiable claim this evidence pipeline
 * exists to rule out. Always present (never omitted), same as `http_status`
 * -- every caller of wpcas_result_record_build() (both CLI controls in
 * docker/wp-cli/measure.php and the HTTP vector in
 * docker/wp-cli/measure-http.php) gathers and passes it, so every record
 * from this schema version carries it, whether or not that particular
 * scenario cares about it.
 *
 * `canary_line` / `canary_fired` (added by issues #6/#7, folded into
 * schema_version 3 additively -- see issue #10's `## Decisions` for why
 * this didn't warrant its own version bump): the section-3 canary guard's
 * own log line, when one fired -- see docker/wp-cli/lib/canary.php for how
 * it's parsed out of PHP's error log, and the various measure-*.php
 * scripts for how that destination is verified writable before being
 * trusted. Optional in the input facts (defaults to `null`) so every
 * caller that never passes it (this ticket's own two CLI-control rows,
 * plus #5's HTTP-vector row) keeps working unchanged; always present in
 * the output, same discipline as `http_status`/`command` -- a vector with
 * no canary guard armed reports `null`, not a missing key. `canary_fired`
 * is derived (`null !== canary_line`), not a separate input, so the two
 * can never disagree -- kept as its own boolean anyway (rather than making
 * a reader infer it from nullability) because issue #7's manual-run vector
 * needs to state plainly, in the record itself, that the canary did NOT
 * fire on that path -- a documented blind spot (that vector calls
 * ActionScheduler_QueueRunner::process_action() directly, bypassing run()
 * and the 'action_scheduler_before_process_queue' hook the canary listens
 * on), not evidence of full coverage.
 *
 * Fields such as `dispatch_decision`, `settle_poll`, and `guard_state`
 * (added by #6/#7's own measure-async-ajax.php / measure-admin-page-load.php
 * / measure-manual-run.php scripts) are layered onto the record returned
 * by wpcas_result_record_build() by those scripts themselves, as
 * additional top-level keys -- they never needed a change to this file's
 * schema function, so they are not enumerated in the @param/@return shapes
 * below, but they are still real, additive record fields and #10's report
 * renders them where present.
 *
 * schema_version 3 -> 4 (issue #33): added `server_observed`, the status
 * the *server* recorded for a measured request -- independently sourced
 * from the client's own report already carried in `http_status`, and tied
 * to the exact request via the `X-Wpcas-Request-Id` header that request
 * sent (see measure-http.php and lib/correlation.php). It carries the web
 * server's (nginx) and process manager's (PHP-FPM) own observed statuses
 * as `web_status`/`fpm_status`, either independently null when no matching
 * access-log line was found for that source. `server_observed` itself is
 * always present (never omitted) and independently nullable -- null as a
 * whole for any row this ticket's correlation doesn't apply to (e.g. a
 * CLI-control row, which never had a request id to correlate on), the same
 * "always present, independently nullable" discipline `http_status`/
 * `command` already established. Optional in the input facts (defaults to
 * null via the same `?? null` pattern as `canary_line`), so every existing
 * caller that never passes it keeps working unchanged.
 *
 * There is no dual-version record support: every record this function
 * builds is schema_version 4, unconditionally, regardless of whether the
 * caller passed `server_observed` -- same as every prior additive field
 * (`cron_in_progress_after`, `canary_line`) never made the schema version a
 * caller-dependent choice.
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
 *     cron_in_progress_after: bool,
 *     canary_line?: string|null,
 *     server_observed?: array{web_status: int|null, fpm_status: int|null}|null,
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

	$canary_line = $facts['canary_line'] ?? null;

	$server_observed = $facts['server_observed'] ?? null;

	return array(
		// Bumped 1 -> 2 (issue #4 follow-up): `command` changed from
		// always-present to nullable, to accommodate the HTTP-vector row
		// shape #5/#6/#7 will produce.
		// Bumped 2 -> 3 (issue #5): added `cron_in_progress_after` (see the
		// module docblock for both bumps).
		// Issue #10 reconciled #6/#7's `canary_line`/`canary_fired` into
		// this same schema_version 3 -- additive fields, no further bump
		// (see the module docblock and issue #10's `## Decisions`).
		// Bumped 3 -> 4 (issue #33): added `server_observed` (see the
		// module docblock's schema_version 3 -> 4 note). No dual-version
		// record support -- every record built here is schema_version 4.
		'schema_version'         => 4,
		'control'                => $facts['control'],
		'command'                => $command,
		// Always present (never omitted), independent of `command` -- see
		// the module docblock for why both fields exist and when each is
		// populated.
		'http_status'            => $facts['http_status'],
		'started_at'             => $facts['started_at'],
		'finished_at'            => $facts['finished_at'],
		'elapsed_seconds'        => $facts['elapsed_seconds'],
		'preflight'              => $facts['preflight'],
		// The "doing_cron" transient's state immediately after the control
		// ran -- see the module docblock's schema_version 2 -> 3 note.
		// `preflight.cron_in_progress` above is the same fact read
		// *before* the control ran; this is its counterpart.
		'cron_in_progress_after' => $facts['cron_in_progress_after'],
		'outcome'                => $outcome,
		'execution_contexts'     => wpcas_result_summarize_execution_contexts( $facts['log_messages'] ),
		'probe_records'          => $facts['probe_records'],
		// Optional in the input facts (see the module docblock); always
		// present in the output, `null` when no canary fired/applies.
		'canary_line'            => $canary_line,
		// Derived, never a separate input -- see the module docblock for
		// why this is still carried as its own boolean rather than left
		// for a reader to infer from `canary_line`'s nullability.
		'canary_fired'           => null !== $canary_line,
		// Optional in the input facts (see the module docblock's
		// schema_version 3 -> 4 note); always present in the output, `null`
		// as a whole for any row this ticket's correlation doesn't apply
		// to. The two sub-fields are themselves independently nullable --
		// see lib/correlation.php's wpcas_correlation_find_pair().
		'server_observed'        => $server_observed,
	);
}
