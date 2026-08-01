<?php

declare( strict_types=1 );

/**
 * Exercises issue #7's first vector -- an authenticated wp-admin page
 * load -- and writes a single timestamped result record through the same
 * canonical schema #4 built (docker/wp-cli/lib/result-record.php),
 * populated as an HTTP-vector row: `command` is null, `http_status` is
 * whatever the page load returned.
 *
 * What this demonstrates: Action Scheduler's queue runner hooks
 * 'shutdown' -> maybe_dispatch_async_request() (see
 * classes/ActionScheduler_QueueRunner.php in the action-scheduler
 * plugin), which only ever attempts a loopback dispatch when
 * `is_admin()` is true (among other checks). A front-end request, a
 * WP-CLI process, or an unauthenticated request that never reaches an
 * admin screen cannot exercise this path at all -- an authenticated
 * wp-admin page load is the *only* way to observe the dispatch-
 * suppression guard (docker/mu-plugins-available/20-suppress-async-
 * dispatch.php) live, which is exactly why this ticket exists.
 *
 * No password anywhere: authentication is a cookie minted in-process via
 * docker/wp-cli/lib/admin-auth.php (wp_set_auth_cookie() under the hood)
 * -- see that file's own docblock, and this ticket's `## Decisions`, for
 * why that satisfies "issue authenticated admin requests without storing
 * a real password" without touching docker/entrypoint.sh's install-time
 * password at all.
 *
 * Async-dispatch throttle lock: cleared unconditionally before the
 * triggering request (see wpcas_probe_clear_async_dispatch_lock() in
 * lib/probe.php) -- Action Scheduler's own dispatch check only attempts a
 * loopback at most once every 60 seconds, so a lock left over from this
 * ticket's own previous run (reset + preflight + measure, repeated within
 * that window) would otherwise make an eligible "unarmed" run silently
 * skip its dispatch and produce a false "nothing drained".
 *
 * Settling: the loopback this page load may trigger is fire-and-forget
 * (see wpcas_probe_poll_until_settled()'s own docblock in lib/probe.php)
 * -- this script's own GET returns long before that second, separate
 * request has necessarily finished draining anything. pending_after is
 * therefore read from a bounded poll, not immediately after the GET
 * returns. That poll only accepts a fast "0 drained" conclusion once it
 * has positively observed *some* progress and then stability -- with no
 * progress at all it holds out for the full wait, so a genuinely
 * dispatched-but-slow-to-start loopback can't be mistaken for
 * suppression (see that function's own docblock for the review finding
 * that prompted this).
 *
 * Dispatch-decision evidence (review follow-up): "0 drained" alone
 * doesn't distinguish "the guard vetoed a real dispatch attempt" from
 * "the dispatch check never ran at all" (e.g. a stale throttle lock).
 * docker/mu-plugins/wpcas-dispatch-decision-probe.php observes Action
 * Scheduler's own 'action_scheduler_allow_async_request_runner' filter
 * (read-only -- it returns the value unchanged) and this script reads
 * that observation back as `dispatch_decision` in the record:
 * `{reached: bool, allowed: bool|null}`. `reached: true` means Action
 * Scheduler's dispatch check ran on this request at all; `allowed`
 * carries whatever value survived every filter on that hook, i.e. the
 * section-2 guard's own effect when it's armed.
 *
 * Canary log destination: verified writable via the same round-trip
 * self-test as issue #6's measure-async-ajax.php, not assumed -- an
 * unwritable/misconfigured destination looks identical to "no canary
 * fired" unless this is checked explicitly.
 *
 * Guard state: `guard_state` in the record lists which of the four guard
 * files were actually present under wp-content/mu-plugins/ for this run
 * (see wpcas_probe_guard_state() in lib/probe.php) -- positive evidence
 * of what was armed, not just the scenario label's say-so.
 *
 * Usage: wp eval-file docker/wp-cli/measure-admin-page-load.php <scenario-label>
 *   <scenario-label>  free-text tag for which guards are armed this run
 *   (e.g. "unarmed", "section-2-armed") -- carried into the record and the
 *   output filename for traceability only; plays no part in behaviour.
 *
 * Invoked via `bin/stack measure-admin-page-load <scenario-label>`, which
 * captures this script's stdout (the JSON record, and nothing else) to a
 * file under results/, same contract as `bin/stack measure`.
 */

require __DIR__ . '/lib/probe.php';
require __DIR__ . '/lib/preflight-assertions.php';
require __DIR__ . '/lib/result-record.php';
require __DIR__ . '/lib/canary.php';
require __DIR__ . '/lib/admin-auth.php';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$scenario = $args[0] ?? '';

if ( '' === $scenario ) {
	WP_CLI::error( 'Usage: wp eval-file docker/wp-cli/measure-admin-page-load.php <scenario-label>' );
}

// --- Canary log destination: verify writable, don't assume ---------------

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

$lockfile_versions = wpcas_probe_lockfile_versions();

$preflight_facts = array(
	'pending_count'                     => wpcas_probe_pending_count(),
	'callback_attached'                 => wpcas_probe_callback_attached(),
	'cron_in_progress'                  => wpcas_probe_cron_in_progress(),
	'claims_count'                      => wpcas_probe_claims_count(),
	'wp_version'                        => wpcas_probe_wp_version(),
	'wp_version_lockfile'               => $lockfile_versions['wordpress'],
	'action_scheduler_version'          => wpcas_probe_action_scheduler_version(),
	'action_scheduler_version_lockfile' => $lockfile_versions['action_scheduler'],
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

// See this file's own docblock -- a lock left over from a previous run
// inside the 60-second throttle window would make an eligible run
// silently skip its own dispatch.
$lock_was_set = wpcas_probe_clear_async_dispatch_lock();
fwrite( STDERR, sprintf( "Async-dispatch throttle lock: %s.\n", $lock_was_set ? 'was set, cleared' : 'was not set' ) );

// Clear any stale dispatch-decision evidence from a previous run before
// this one's triggering request -- see this file's own docblock and
// docker/mu-plugins/wpcas-dispatch-decision-probe.php.
wpcas_probe_reset_dispatch_decision();

// Positive evidence of what was actually armed for this run -- see this
// file's own docblock and wpcas_probe_guard_state() in lib/probe.php.
$guard_state = wpcas_probe_guard_state();
fwrite( STDERR, 'Guard state: ' . wp_json_encode( $guard_state ) . "\n" );

// --- Mint the admin session, no password stored or transmitted -----------

$session = wpcas_admin_mint_session( 'admin' );

fwrite(
	STDERR,
	sprintf(
		"Minted admin session for user '%s' (id %d) via wp_set_auth_cookie() -- no password read or stored.\n",
		$session['user_login'],
		$session['user_id']
	)
);

// plugins.php: a real, ordinary wp-admin screen with no dashboard-widget
// external-feed fetches to add noise/latency to this measurement -- any
// admin screen would exercise the same is_admin()-gated dispatch check
// (see this file's own docblock), this one is just a plain, fast choice.
$target_url = admin_url( 'plugins.php' );

// Issue #29: admin_url() resolves against WP_SITEURL, "localhost:$STACK_PORT"
// -- correct for a real external client, but nginx (not this php-fpm
// container) is what actually listens there now, on a separate Compose
// service. This works anyway: docker/mu-plugins/wpcas-internal-loopback-resolve.php
// transparently redirects the *connection* (not the Host header WordPress
// still sends) to nginx for any loopback built from this site's own
// siteurl -- the same fix Action Scheduler's own internal async-dispatch
// loopback needs and gets, since this repo can't edit that vendored code
// directly (and this scenario's whole point is that dispatch actually
// firing from this page load). Nothing below needs to know any of that.

// Byte offset into the canary log *before* the request, so the canary-line
// read afterwards is scoped to this run only.
clearstatcache( true, $canary_log_path );
$canary_log_offset_before = file_exists( $canary_log_path ) ? (int) filesize( $canary_log_path ) : 0;

fwrite(
	STDERR,
	sprintf(
		"Preflight passed. Issuing authenticated GET %s against %d pending action(s)...\n",
		$target_url,
		$pending_before
	)
);

$started_at = gmdate( 'c' );
$start_time = microtime( true );

$response = wp_remote_get(
	esc_url_raw( $target_url ),
	array(
		'timeout'   => 30,
		'cookies'   => $session['cookies'],
		'sslverify' => false,
	)
);

$page_load_elapsed_seconds = microtime( true ) - $start_time;

$http_status     = null;
$transport_error = null;

if ( is_wp_error( $response ) ) {
	$transport_error = $response->get_error_message();
	fwrite( STDERR, "--- transport error ---\n{$transport_error}\n" );
} else {
	$http_status = wp_remote_retrieve_response_code( $response );
	fwrite( STDERR, sprintf( "--- response ---\nstatus: %s\nbody length: %d bytes\n", $http_status, strlen( (string) wp_remote_retrieve_body( $response ) ) ) );
}

fwrite(
	STDERR,
	sprintf( "Page load itself finished in %.3fs. Polling for the queue to settle (see lib/probe.php's own docblock)...\n", $page_load_elapsed_seconds )
);

// --- Poll for settlement, then capture post-run state ---------------------
//
// See wpcas_probe_poll_until_settled()'s own docblock: the dispatch this
// page load may have triggered is fire-and-forget, so pending_after is
// read from a bounded poll, not immediately after the GET above returns.
// $pending_before is passed in so the poll can tell "never moved" apart
// from "moved and then stabilised" -- only the latter is allowed to exit
// early.
$poll = wpcas_probe_poll_until_settled( $pending_before, 30 );

$elapsed_seconds = microtime( true ) - $start_time;
$finished_at     = gmdate( 'c' );

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
		"Dispatch decision: reached=%s, allowed=%s.\n",
		$dispatch_decision['reached'] ? 'true' : 'false',
		null === $dispatch_decision['allowed'] ? 'n/a' : ( $dispatch_decision['allowed'] ? 'true' : 'false' )
	)
);

// Canary: read only what was appended to the log during this run.
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
	fwrite( STDERR, "Canary: no line observed for this run.\n" );
} else {
	fwrite( STDERR, sprintf( "Canary: %d line(s) observed for this run:\n%s\n", count( $canary_lines ), $canary_line ) );
}

$record = wpcas_result_record_build(
	array(
		'control'           => 'admin-page-load:' . $scenario,
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
	'name'                => 'authenticated-admin-page-load',
	'target_url'          => $target_url,
	'authenticated_as'    => $session['user_login'],
	'authenticated_user_id' => $session['user_id'],
	'auth_method'         => 'wp_set_auth_cookie() minted in-process (docker/wp-cli/lib/admin-auth.php) -- no password read, stored, or transmitted',
	'transport_error'     => $transport_error,
);
$record['settle_poll']            = array(
	'waited_seconds'    => $poll['waited_seconds'],
	'settled'           => $poll['settled'],
	'timed_out'         => $poll['timed_out'],
	'progress_observed' => $poll['progress_observed'],
);
$record['canary_log_writability'] = array(
	'path'              => $canary_log_path,
	'verified_writable' => $log_writable,
);
// Positive evidence that Action Scheduler's own dispatch decision point
// was reached (or not) on this run, and what it resolved to -- see this
// file's own docblock and docker/mu-plugins/wpcas-dispatch-decision-probe.php.
$record['dispatch_decision']      = $dispatch_decision;
// Positive evidence of what was actually armed for this run -- see this
// file's own docblock and wpcas_probe_guard_state() in lib/probe.php.
$record['guard_state']            = $guard_state;

fwrite(
	STDERR,
	sprintf(
		"Scenario '%s' finished: %d -> %d pending (drained %d) in %.3fs total, http_status=%s.\n",
		$scenario,
		$pending_before,
		$pending_after,
		$record['outcome']['drained'],
		$elapsed_seconds,
		null === $http_status ? 'n/a' : (string) $http_status
	)
);

// The result record, and only the result record, goes to STDOUT -- see
// bin/stack's `measure-admin-page-load` subcommand.
echo wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
