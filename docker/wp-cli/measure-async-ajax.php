<?php

declare( strict_types=1 );

/**
 * Exercises issue #6's vector -- an unauthenticated POST to Action
 * Scheduler's own async-request ajax action -- and writes a single
 * timestamped result record through the same canonical schema #4 built
 * (docker/wp-cli/lib/result-record.php), populated as an HTTP-vector row:
 * `command` is null, `http_status` is whatever the endpoint returned.
 *
 * What this demonstrates: Action Scheduler registers
 * `as_async_request_queue_runner` for logged-out callers too
 * (`wp_ajax_nopriv_...`, see ActionScheduler_AsyncRequest_QueueRunner /
 * WP_Async_Request in the plugin's own lib/), and its handler
 * (maybe_handle() -> handle()) has no permission check of its own -- only
 * `check_ajax_referer()`, i.e. a nonce check. Whatever the first three
 * guard sections do or don't block, this script hits that endpoint
 * exactly the way an anonymous attacker would: mint a nonce, POST it,
 * read what comes back.
 *
 * Open risk this ticket settles (see the module docblock note below at
 * the nonce-minting step): whether a nonce minted from a WP-CLI process
 * validates for a genuinely unauthenticated HTTP caller. This script's
 * own successful runs (a 50-drain with sections 1-3 armed) are the
 * empirical answer, not an assumption -- see the PR/issue writeup for the
 * reasoning (WP-CLI's default bootstrap is uid 0 with no session token,
 * same as an anonymous visitor, so `wp_create_nonce()` here and
 * `wp_verify_nonce()` on the anonymous request agree) and this script's
 * own STDERR diagnostics for what was actually observed on this run.
 *
 * Canary writability (acceptance criterion: "the canary log destination
 * is verified writable rather than assumed"): before doing anything else,
 * this script writes a unique marker via PHP's own error_log() and reads
 * it back from the same destination php.ini's `error_log` directive
 * names. An unwritable/misconfigured destination looks identical to a
 * canary that never fired unless this is checked explicitly -- so a
 * failed self-test aborts the run (same abort contract as
 * docker/wp-cli/measure.php: non-zero exit, no result record written)
 * rather than silently producing a record that can't tell "no canary
 * fired" apart from "the canary fired somewhere nobody can read".
 *
 * Usage: wp eval-file docker/wp-cli/measure-async-ajax.php <scenario-label>
 *   <scenario-label> is a free-text tag describing which guards are armed
 *   for this run (e.g. "sections-1-2-3-armed", "unhook-armed") -- carried
 *   into the result record and the output filename purely for
 *   traceability across the two runs this ticket requires; it plays no
 *   part in what the script actually does.
 *
 * Invoked via `bin/stack measure-async-ajax <scenario-label>`, which
 * captures this script's stdout (the JSON record, and nothing else) to a
 * file under results/, same convention as `bin/stack measure`.
 *
 * Preconditions this script enforces itself: the canary-log-writability
 * self-test (above), then the same preflight gate as measure.php
 * (non-zero exit, no record written, on failure). Callers are still
 * expected to have run `bin/stack reset <count> due-now` first.
 */

require __DIR__ . '/lib/probe.php';
require __DIR__ . '/lib/preflight-assertions.php';
require __DIR__ . '/lib/result-record.php';
require __DIR__ . '/lib/canary.php';

/**
 * Action Scheduler's own async-request ajax action name (see
 * ActionScheduler_AsyncRequest_QueueRunner::$prefix / $action in the
 * action-scheduler plugin: prefix 'as' + '_' + action
 * 'async_request_queue_runner'). Not invented here -- this is exactly the
 * literal string WordPress registers `wp_ajax_as_async_request_queue_runner`
 * and `wp_ajax_nopriv_as_async_request_queue_runner` under, and exactly
 * the literal string `wp_create_nonce()`/`check_ajax_referer()` use as the
 * nonce action.
 */
const WPCAS_ASYNC_AJAX_ACTION = 'as_async_request_queue_runner';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$scenario = $args[0] ?? '';

if ( '' === $scenario ) {
	WP_CLI::error( 'Usage: wp eval-file docker/wp-cli/measure-async-ajax.php <scenario-label>' );
}

// --- Canary log destination: verify writable, don't assume ---------------
//
// docker/Dockerfile points php.ini's `error_log` at a concrete file
// (/var/log/wpcas/php-error.log) for exactly this reason. Read it back
// from ini_get() rather than hard-coding that path here, so this check
// actually exercises the same configuration resolution PHP itself uses.

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

// Give a real (non-buffered-in-a-way-that-hides-failure) filesystem write
// a moment to land before reading it back. error_log() to a file target
// is a direct fopen()/fwrite() in PHP, not asynchronous, but this keeps
// the check robust against network filesystems or unusual stream wrappers
// without assuming either way.
clearstatcache( true, $canary_log_path );
$selftest_contents = is_readable( $canary_log_path ) ? (string) file_get_contents( $canary_log_path ) : '';
$log_writable       = false !== strpos( $selftest_contents, $writability_marker );

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

// Byte offset into the canary log *before* the exploit request, so the
// canary-line read afterwards is scoped to this run only -- not any
// earlier marker/canary content already in the file (including this
// script's own writability self-test line, just written above).
clearstatcache( true, $canary_log_path );
$canary_log_offset_before = file_exists( $canary_log_path ) ? (int) filesize( $canary_log_path ) : 0;

// --- Mint the nonce -------------------------------------------------------
//
// Minted exactly as WP-CLI's own default bootstrap leaves things: no
// --user was passed anywhere in this stack's tooling (bin/stack/bin/guard
// never pass one), so get_current_user_id() here is 0 -- the same
// identity an anonymous HTTP visitor has. wp_create_nonce() folds in the
// current user id and their session token (empty for a logged-out
// session) along with the action string and a 12-hour tick; matching both
// of those is what makes this nonce validate for the *actual* anonymous
// POST below, not merely for this WP-CLI process.
$current_user_id = get_current_user_id();
$nonce            = wp_create_nonce( WPCAS_ASYNC_AJAX_ACTION );

fwrite(
	STDERR,
	sprintf(
		"Minted nonce for action '%s' as user id %d (0 = logged-out/anonymous identity).\n",
		WPCAS_ASYNC_AJAX_ACTION,
		$current_user_id
	)
);

// --- Build the request, mirroring WP_Async_Request::dispatch() -----------
//
// The library method itself (lib/WP_Async_Request.php in action-scheduler)
// puts both `action` and `nonce` in the query string of a POST request
// with an empty body -- reproduced here exactly, rather than moving them
// into the POST body, so this is the same request shape a real attacker
// following the library's own dispatch code would send.
$target_url = add_query_arg(
	array(
		'action' => WPCAS_ASYNC_AJAX_ACTION,
		'nonce'  => $nonce,
	),
	admin_url( 'admin-ajax.php' )
);

// Issue #29: admin_url() resolves against WP_SITEURL, "localhost:$STACK_PORT"
// -- correct for a real external client, but nginx (not this php-fpm
// container) is what actually listens there now, on a separate Compose
// service. This works anyway: docker/mu-plugins/wpcas-internal-loopback-resolve.php
// transparently redirects the *connection* (not the Host header WordPress
// still sends) to nginx for any loopback built from this site's own
// siteurl -- the same fix Action Scheduler's own internal async-dispatch
// loopback needs and gets, since this repo can't edit that vendored code
// directly. Nothing below needs to know any of that.
fwrite( STDERR, sprintf( "Preflight passed. POSTing to %s (no cookies, no auth) against %d pending action(s)...\n", $target_url, $pending_before ) );

$started_at = gmdate( 'c' );
$start_time = microtime( true );

// blocking=true: unlike Action Scheduler's own internal dispatch (which
// fires-and-forgets with a 0.01s timeout), this script needs the actual
// response to report the HTTP status faithfully -- and handle() sleeps up
// to 5s (action_scheduler_async_request_sleep_seconds) before returning,
// so the timeout below has to cover that.
// cookies => array(): explicit, not just "whatever wp_remote_post()
// defaults to" -- this must be a request with no session of any kind, the
// same as a real anonymous caller.
$response = wp_remote_post(
	esc_url_raw( $target_url ),
	array(
		'timeout'   => 30,
		'blocking'  => true,
		'body'      => array(),
		'cookies'   => array(),
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
	$body        = wp_remote_retrieve_body( $response );
	fwrite( STDERR, sprintf( "--- response ---\nstatus: %s\nbody: %s\n", $http_status, $body ) );
}

// --- Capture post-run state ------------------------------------------------

$pending_after = wpcas_probe_pending_count();
$log_messages  = wpcas_probe_log_messages_for_actions( $action_ids );
$probe_records = wpcas_probe_execution_log_entries();

// Reconciled onto this vector by issue #10: every record now carries
// `cron_in_progress_after` (issue #5's schema_version 3 field), not just
// the CLI controls and the unauthenticated-GET HTTP vector -- see
// lib/result-record.php's module docblock.
$cron_in_progress_after = wpcas_probe_cron_in_progress();

// Canary: read only what was appended to the log during this run (from
// the offset captured before the request), so this run's line(s) can't be
// confused with the writability self-test line or any earlier run's.
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
	fwrite( STDERR, "Canary: no line observed for this run (expected when section 3 is disarmed, or when the queue never actually ran).\n" );
} else {
	fwrite( STDERR, sprintf( "Canary: %d line(s) observed for this run:\n%s\n", count( $canary_lines ), $canary_line ) );
}

$record = wpcas_result_record_build(
	array(
		'control'           => 'async-ajax:' . $scenario,
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
// shape rather than folded into it -- see lib/result-record.php's module
// docblock: the canonical schema is `command`/`http_status`/outcome/etc,
// shared by every vector ticket; a nonce-minting identity, a target URL,
// and a transport-level error are this ticket's own evidence, not
// something #5/#7/#10 need to know the shape of.
$record['vector']              = array(
	'name'                  => 'unauthenticated-admin-ajax-async-runner',
	'target_url'            => $target_url,
	'nonce_action'          => WPCAS_ASYNC_AJAX_ACTION,
	'nonce_minted_as_user_id' => $current_user_id,
	'transport_error'       => $transport_error,
);
$record['canary_log_writability'] = array(
	'path'              => $canary_log_path,
	'verified_writable' => $log_writable,
);

fwrite(
	STDERR,
	sprintf(
		"Scenario '%s' finished: %d -> %d pending (drained %d) in %.3fs, http_status=%s.\n",
		$scenario,
		$pending_before,
		$pending_after,
		$record['outcome']['drained'],
		$elapsed_seconds,
		null === $http_status ? 'n/a' : (string) $http_status
	)
);

// The result record, and only the result record, goes to STDOUT -- see
// bin/stack's `measure-async-ajax` subcommand.
echo wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
