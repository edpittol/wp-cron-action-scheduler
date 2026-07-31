<?php

declare( strict_types=1 );

/**
 * Exercises issue #7's second vector -- a manual "Run" click on a single
 * pending action from the Scheduled Actions admin screen -- and writes a
 * single timestamped result record through the same canonical schema #4
 * built (docker/wp-cli/lib/result-record.php), populated as an HTTP-
 * vector row: `command` is null, `http_status` is whatever the request
 * returned.
 *
 * What this demonstrates, and its deliberate blind spot: the list table's
 * row action (classes/ActionScheduler_ListTable.php::row_action_run() ->
 * process_row_action() in the action-scheduler plugin) calls
 * `$this->runner->process_action( $action_id, 'Admin List Table' )`
 * directly -- it never calls run() and never fires
 * 'action_scheduler_before_process_queue', the hook the section-3 canary
 * guard (docker/mu-plugins-available/30-log-non-cli-canary.php) listens
 * on. This is authenticated, single-action, and deliberately not blocked
 * by any of this ticket series' guards -- expected to execute exactly one
 * action even with all four sections armed -- but it also means the
 * canary cannot see it. That is a real, documented gap in the canary's
 * coverage, not something this script tries to paper over: the result
 * record's `canary_line`/`canary_fired` fields are expected to read
 * null/false here, and `vector.canary_blind_spot` below states why in the
 * record itself.
 *
 * The exact request this script reproduces (row_action / row_id / nonce
 * query args, and the nonce's action string `"run::{$action_id}"`) is
 * read directly from ActionScheduler_Abstract_ListTable::maybe_render_actions()
 * / process_row_actions() in the vendored plugin -- not guessed.
 *
 * No password anywhere: same in-process cookie-minting as
 * measure-admin-page-load.php -- see docker/wp-cli/lib/admin-auth.php.
 *
 * Nonce minting: wp_create_nonce( "run::{$action_id}" ) is called in this
 * same WP-CLI process, immediately after wpcas_admin_mint_session() seeds
 * $_COOKIE[LOGGED_IN_COOKIE] with the freshly minted cookie value (see
 * that function's own docblock) -- so the nonce this script mints and the
 * nonce the real HTTP request's own wp_verify_nonce() call independently
 * recomputes agree: same user id (from the AUTH_COOKIE this request also
 * carries), same session token (from that shared LOGGED_IN_COOKIE value),
 * same action string.
 *
 * Usage: wp eval-file docker/wp-cli/measure-manual-run.php <scenario-label>
 *   <scenario-label>  free-text tag for which guards are armed this run
 *   (e.g. "sections-1-2-3-4-armed") -- carried into the record and the
 *   output filename for traceability only; plays no part in behaviour.
 *
 * Invoked via `bin/stack measure-manual-run <scenario-label>`, which
 * captures this script's stdout (the JSON record, and nothing else) to a
 * file under results/, same contract as `bin/stack measure`.
 *
 * Guard state (review follow-up): the record's `guard_state` field lists
 * which of the four guard files were actually present under
 * wp-content/mu-plugins/ for this run (see wpcas_probe_guard_state() in
 * lib/probe.php), plus whether the section-3 canary's action hook was
 * actually registered. Without this, a reader of a null `canary_line`
 * here can't tell "armed but blind to this path" (this vector's real,
 * documented finding) apart from "never armed in the first place" (which
 * would make that finding meaningless) -- this field is what makes that
 * distinction provable from the record itself.
 *
 * Dispatch-decision evidence: this manual-run request is still, itself,
 * an authenticated wp-admin page load underneath, so it also passes
 * through Action Scheduler's own is_admin()-gated async-dispatch decision
 * point (see measure-admin-page-load.php's own docblock for the full
 * explanation). Recording it here too corroborates *why* this run drains
 * exactly one action and not more: the section-2 guard (armed for this
 * scenario) vetoes that dispatch as well, not just the one this script
 * intentionally triggers via the row-action link.
 */

require __DIR__ . '/lib/probe.php';
require __DIR__ . '/lib/preflight-assertions.php';
require __DIR__ . '/lib/result-record.php';
require __DIR__ . '/lib/canary.php';
require __DIR__ . '/lib/admin-auth.php';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$scenario = $args[0] ?? '';

if ( '' === $scenario ) {
	WP_CLI::error( 'Usage: wp eval-file docker/wp-cli/measure-manual-run.php <scenario-label>' );
}

// --- Canary log destination: verify writable, don't assume ---------------
//
// Especially important for this vector: its whole point is to show the
// canary staying silent. A silence that's actually just an unwritable log
// destination would look identical -- this self-test is what tells them
// apart.

$canary_log_path = (string) ini_get( 'error_log' );

if ( '' === $canary_log_path ) {
	WP_CLI::error(
		"php.ini's error_log directive is empty -- error_log() calls (including the section-3 canary guard's) " .
		'would fall back to the SAPI\'s own logger, not a file this script can verify or read back. Refusing to run.'
	);
}

if ( ! is_writable( dirname( $canary_log_path ) ) && ( ! file_exists( $canary_log_path ) || ! is_writable( $canary_log_path ) ) ) {
	WP_CLI::error( "Canary log destination '{$canary_log_path}' is not writable. Refusing to run." );
}

$writability_marker = sprintf( 'wpcas-canary-writability-selftest-%s-%d', $scenario, getmypid() );
error_log( $writability_marker ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

clearstatcache( true, $canary_log_path );
$selftest_contents = is_readable( $canary_log_path ) ? (string) file_get_contents( $canary_log_path ) : '';
$log_writable      = false !== strpos( $selftest_contents, $writability_marker );

if ( ! $log_writable ) {
	WP_CLI::error(
		"Wrote a marker via error_log() but it did not appear in '{$canary_log_path}' on read-back. " .
		'This destination cannot be trusted to carry the canary guard\'s line -- refusing to run rather than ' .
		'producing a record that can\'t distinguish "no canary fired" from "the canary fired somewhere unread".'
	);
}

fwrite( STDERR, "Canary log destination '{$canary_log_path}' verified writable (marker round-tripped).\n" );

// --- Preflight (same facts/evaluation as preflight.php/measure.php) -----

$preflight_facts = array(
	'pending_count'     => wpcas_probe_pending_count(),
	'callback_attached' => wpcas_probe_callback_attached(),
	'cron_in_progress'  => wpcas_probe_cron_in_progress(),
	'claims_count'      => wpcas_probe_claims_count(),
);
$preflight = wpcas_preflight_evaluate( $preflight_facts );

fwrite( STDERR, wp_json_encode( $preflight['snapshot'], JSON_PRETTY_PRINT ) . "\n" );

if ( ! $preflight['ok'] ) {
	fwrite( STDERR, "Preflight FAILED, refusing to measure:\n" );
	foreach ( $preflight['failures'] as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	WP_CLI::halt( 1 );
}

// --- Capture pre-run state, scoped to exactly this batch -----------------

$pending_before = $preflight_facts['pending_count'];
$action_ids     = wpcas_probe_pending_action_ids();

if ( array() === $action_ids ) {
	WP_CLI::error( 'No pending probe actions to run manually -- run `bin/stack reset <count> due-now` first.' );
}

// All four guard sections are expected armed for this scenario (see the
// ticket): section 2 (suppress async dispatch) matters even here, because
// this is still an authenticated wp-admin page load underneath, and would
// otherwise ALSO trigger Action Scheduler's own is_admin()-gated shutdown
// dispatch (see measure-admin-page-load.php) alongside the manual run --
// draining more than the single targeted action and ruining "exactly one"
// as a measurement. Cleared defensively regardless of which sections are
// actually armed for this invocation, same reasoning as
// measure-admin-page-load.php's own call.
$lock_was_set = wpcas_probe_clear_async_dispatch_lock();
fwrite( STDERR, sprintf( "Async-dispatch throttle lock: %s.\n", $lock_was_set ? 'was set, cleared' : 'was not set' ) );

// Clear any stale dispatch-decision evidence from a previous run -- see
// this file's own docblock and docker/mu-plugins/wpcas-dispatch-decision-probe.php.
wpcas_probe_reset_dispatch_decision();

// Positive evidence of what was actually armed for this run -- see this
// file's own docblock and wpcas_probe_guard_state() in lib/probe.php.
$guard_state = wpcas_probe_guard_state();
fwrite( STDERR, 'Guard state: ' . wp_json_encode( $guard_state ) . "\n" );

// Exactly one action, chosen deterministically (lowest ID = earliest
// seeded) so this script's own behaviour doesn't depend on iteration
// order of anything Action Scheduler returns.
$target_action_id = min( $action_ids );

// --- Mint the admin session and a matching nonce, no password stored -----

$session = wpcas_admin_mint_session( 'admin' );

fwrite(
	STDERR,
	sprintf(
		"Minted admin session for user '%s' (id %d) via wp_set_auth_cookie() -- no password read or stored.\n",
		$session['user_login'],
		$session['user_id']
	)
);

// Nonce action string is exactly "{$action_key}::{$row_id}", per
// ActionScheduler_Abstract_ListTable::maybe_render_actions() -- see this
// file's own docblock.
$run_nonce = wp_create_nonce( 'run::' . $target_action_id );

$target_url = add_query_arg(
	array(
		'page'       => 'action-scheduler',
		'row_action' => 'run',
		'row_id'     => $target_action_id,
		'nonce'      => $run_nonce,
	),
	admin_url( 'tools.php' )
);

// Byte offset into the canary log *before* the request.
clearstatcache( true, $canary_log_path );
$canary_log_offset_before = file_exists( $canary_log_path ) ? (int) filesize( $canary_log_path ) : 0;

fwrite(
	STDERR,
	sprintf(
		"Preflight passed. Issuing authenticated GET %s (manual run of action id %d) against %d pending action(s)...\n",
		$target_url,
		$target_action_id,
		$pending_before
	)
);

$started_at = gmdate( 'c' );
$start_time = microtime( true );

// process_row_actions() always redirects (wp_safe_redirect + exit) after
// acting, whether or not the nonce/parameters validated -- redirection
// left at WP_Http's own default so this request follows it like a real
// browser would and reports the final page's status, not the
// intermediate 302's.
$response = wp_remote_get(
	esc_url_raw( $target_url ),
	array(
		'timeout'   => 30,
		'cookies'   => $session['cookies'],
		'sslverify' => false,
	)
);

$elapsed_seconds = microtime( true ) - $start_time;
$finished_at     = gmdate( 'c' );

$http_status     = null;
$transport_error = null;

if ( is_wp_error( $response ) ) {
	$transport_error = $response->get_error_message();
	fwrite( STDERR, "--- transport error ---\n{$transport_error}\n" );
} else {
	$http_status = wp_remote_retrieve_response_code( $response );
	fwrite( STDERR, sprintf( "--- response ---\nstatus: %s\nbody length: %d bytes\n", $http_status, strlen( (string) wp_remote_retrieve_body( $response ) ) ) );
}

// process_action() runs synchronously within the same request that
// process_row_actions() handles -- no async loopback involved on this
// path (contrast measure-admin-page-load.php) -- so a short settle poll
// is enough; it is expected to report already-settled on its very first
// read. $pending_before is passed in per wpcas_probe_poll_until_settled()'s
// updated signature (review follow-up) -- see that function's own
// docblock.
$poll = wpcas_probe_poll_until_settled( $pending_before, 10 );

fwrite(
	STDERR,
	sprintf(
		"Poll finished after %.3fs (settled=%s, timed_out=%s, progress_observed=%s): pending=%d.\n",
		$poll['waited_seconds'],
		$poll['settled'] ? 'true' : 'false',
		$poll['timed_out'] ? 'true' : 'false',
		$poll['progress_observed'] ? 'true' : 'false',
		$poll['pending']
	)
);

// --- Capture post-run state ------------------------------------------------

$pending_after     = $poll['pending'];
$log_messages      = wpcas_probe_log_messages_for_actions( $action_ids );
$probe_records     = wpcas_probe_execution_log_entries();
$dispatch_decision = wpcas_probe_read_dispatch_decision();

// Reconciled onto this vector by issue #10: every record now carries
// `cron_in_progress_after` (issue #5's schema_version 3 field), not just
// the CLI controls and the unauthenticated-GET HTTP vector -- see
// lib/result-record.php's module docblock.
$cron_in_progress_after = wpcas_probe_cron_in_progress();

fwrite(
	STDERR,
	sprintf(
		"Dispatch decision (this page load's own, separate from the targeted manual run): reached=%s, allowed=%s.\n",
		$dispatch_decision['reached'] ? 'true' : 'false',
		null === $dispatch_decision['allowed'] ? 'n/a' : ( $dispatch_decision['allowed'] ? 'true' : 'false' )
	)
);

// Canary: read only what was appended to the log during this run. Expected
// to be empty for this vector -- see this file's own docblock.
clearstatcache( true, $canary_log_path );
$canary_log_new_contents = '';
if ( file_exists( $canary_log_path ) ) {
	$handle = fopen( $canary_log_path, 'rb' );
	if ( false !== $handle ) {
		fseek( $handle, $canary_log_offset_before );
		$canary_log_new_contents = (string) stream_get_contents( $handle );
		fclose( $handle );
	}
}
$canary_lines = wpcas_canary_extract_lines( $canary_log_new_contents );
$canary_line  = wpcas_canary_join_lines( $canary_lines );

if ( array() === $canary_lines ) {
	fwrite( STDERR, "Canary: no line observed for this run (expected -- process_action() bypasses the queue hook the canary listens on; see this file's own docblock).\n" );
} else {
	fwrite(
		STDERR,
		sprintf(
			"Canary: %d line(s) UNEXPECTEDLY observed for this run (contradicts this vector's documented blind spot -- investigate):\n%s\n",
			count( $canary_lines ),
			$canary_line
		)
	);
}

$record = wpcas_result_record_build(
	array(
		'control'           => 'manual-run:' . $scenario,
		'command_argv'      => null,
		'command_exit_code' => null,
		'http_status'       => $http_status,
		'started_at'        => $started_at,
		'finished_at'       => $finished_at,
		'elapsed_seconds'   => $elapsed_seconds,
		'preflight'         => $preflight['snapshot'],
		'pending_before'    => $pending_before,
		'pending_after'     => $pending_after,
		'log_messages'      => $log_messages,
		'probe_records'     => $probe_records,
		'cron_in_progress_after' => $cron_in_progress_after,
		'canary_line'       => $canary_line,
	)
);

// Fields specific to this HTTP vector, layered on top of the canonical
// shape -- see lib/result-record.php's module docblock.
$record['vector']                 = array(
	'name'                   => 'authenticated-manual-run-scheduled-actions-admin-screen',
	'target_url'             => $target_url,
	'target_action_id'       => $target_action_id,
	'authenticated_as'       => $session['user_login'],
	'authenticated_user_id'  => $session['user_id'],
	'auth_method'            => 'wp_set_auth_cookie() minted in-process (docker/wp-cli/lib/admin-auth.php) -- no password read, stored, or transmitted',
	'transport_error'        => $transport_error,
	// The finding this ticket exists to capture explicitly, not just
	// leave implicit in a null field -- see this file's own docblock.
	'canary_blind_spot'      => 'This path calls ActionScheduler_QueueRunner::process_action() directly ' .
		'(classes/ActionScheduler_ListTable.php::process_row_action(), case \'run\'), never calling run() and never ' .
		'firing \'action_scheduler_before_process_queue\' -- the hook the section-3 canary guard listens on. ' .
		'A null canary_line here means the canary cannot see this entry point, not that nothing ran: ' .
		'outcome.drained above is the actual evidence this action executed.',
);
$record['canary_log_writability'] = array(
	'path'              => $canary_log_path,
	'verified_writable' => $log_writable,
);
// Settle-poll diagnostics, same shape as measure-admin-page-load.php's
// record -- expected to show progress_observed=true almost immediately
// here, since process_action() runs synchronously within the same
// request (contrast the fire-and-forget loopback that vector polls for).
$record['settle_poll']            = array(
	'waited_seconds'    => $poll['waited_seconds'],
	'settled'           => $poll['settled'],
	'timed_out'         => $poll['timed_out'],
	'progress_observed' => $poll['progress_observed'],
);
// Positive evidence of what was actually armed for this run -- this is
// what lets "canary did not fire" be read as "armed but blind to this
// path" rather than "never armed" -- see this file's own docblock.
$record['guard_state']            = $guard_state;
// This page load's own dispatch decision (separate from the row-action
// run this script targets) -- see this file's own docblock.
$record['dispatch_decision']      = $dispatch_decision;

fwrite(
	STDERR,
	sprintf(
		"Scenario '%s' finished: %d -> %d pending (drained %d) in %.3fs, http_status=%s, canary_fired=%s.\n",
		$scenario,
		$pending_before,
		$pending_after,
		$record['outcome']['drained'],
		$elapsed_seconds,
		null === $http_status ? 'n/a' : (string) $http_status,
		$record['canary_fired'] ? 'true' : 'false'
	)
);

// The result record, and only the result record, goes to STDOUT -- see
// bin/stack's `measure-manual-run` subcommand.
echo wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
