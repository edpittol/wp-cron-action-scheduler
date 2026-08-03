<?php

declare( strict_types=1 );

/**
 * Measures the HTTP vector (issue #5): an unauthenticated GET to
 * wp-cron.php, issued as a genuinely separate HTTP request against this
 * stack's own running nginx + PHP-FPM server model (issue #29; previously
 * PHP's built-in server) -- not a same-process simulation of one. Same
 * evidence pipeline as docker/wp-cli/measure.php (issue #4): preflight
 * first, refuse to measure on failure, then build and write the canonical
 * result record (docker/wp-cli/lib/result-record.php, schema_version 3).
 *
 * This is the HTTP-vector row shape that file's own docblock reserves for
 * #5/#6/#7: `command` is always null (no WP-CLI command runs here) and
 * `http_status` is populated with whatever this request's status line
 * said. The record also carries `cron_in_progress_after` -- the
 * "doing_cron" transient's state read immediately after this request --
 * so the armed scenario's "no cron-in-progress transient left behind"
 * acceptance criterion is provable from the committed record itself, not
 * from an uncommitted follow-up preflight taken on trust.
 *
 * Under PHP-FPM (unlike the built-in server this stack ran under before
 * issue #29), core's wp-cron.php calls `fastcgi_finish_request()` before
 * mu-plugins load, which flushes and closes the response to the client as
 * an already-sent 200 before any mu-plugin code (including the guard this
 * measures) ever runs -- this is the masking finding docs/adr/0001 exists
 * to make measurable on this stack. `http_status` here does not yet prove
 * that either way; recording and interpreting it as such is out of scope
 * for this ticket (see docker/mu-plugins-available/10-block-http-cron.php's
 * own docblock for the guard-level caveat this predates). Recorded
 * regardless, per issue #5's own decision ("status codes are recorded but
 * never used to determine outcome") -- wpcas_result_compute_outcome()
 * never takes it as an input; outcome is always pending-count-before/after
 * plus the probe's own execution log.
 *
 * That same early flush is also why `pending_after` is read from a
 * bounded settle poll (`wpcas_probe_poll_until_settled()`, lib/probe.php)
 * now, not immediately after this script's own GET returns: the response
 * this script receives can be fully closed out before wp-cron.php has
 * even started draining anything, since draining happens *after* the
 * flush, in the same worker. Reading pending_after synchronously (as this
 * script did before issue #29, correctly, when the built-in server always
 * finished draining before responding at all) would race that
 * still-running background work and misreport a false "0 drained" on
 * every unarmed run.
 *
 * Usage: wp eval-file docker/wp-cli/measure-http.php <label>
 *   <label>  a free-form scenario label (e.g. "http-cron-unarmed"), used
 *            only as the record's `control` field and (via bin/stack
 *            measure-http) the result file's name -- never used to decide
 *            anything.
 *
 * Invoked via `bin/stack measure-http <label>`, which captures this
 * script's stdout (the JSON record, and nothing else) to a file under
 * results/, same contract as `bin/stack measure`.
 */

require __DIR__ . '/lib/probe.php';
require __DIR__ . '/lib/preflight-assertions.php';
require __DIR__ . '/lib/result-record.php';
require __DIR__ . '/lib/http-status.php';
require __DIR__ . '/lib/internal-loopback.php';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$control = $args[0] ?? 'http-wp-cron';

// --- Preflight (same facts/evaluation as preflight.php / measure.php) ---

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

// Deliberately a bare GET, no query string. wp-cron.php (wp-includes,
// core) branches on whether $_GET['doing_wp_cron'] is present:
//
//   if ( empty( $_GET['doing_wp_cron'] ) ) {
//       // Called from external script/job. Try setting a lock.
//       ...
//       $doing_wp_cron = sprintf( '%.22F', microtime( true ) );
//       set_transient( 'doing_cron', $doing_wp_cron );
//   } else {
//       $doing_wp_cron = $_GET['doing_wp_cron'];
//   }
//   if ( $doing_cron_transient !== $doing_wp_cron ) {
//       return;
//   }
//
// Found the hard way while building this measurement: an earlier version
// of this script appended its own `?doing_wp_cron=<microtime>` (meant to
// "look like" the query string core's own spawn_cron() adds when *it*
// calls this file, e.g. via the loopback). That took the `else` branch
// instead of the lock-acquiring `if` branch -- the request never sets its
// own transient, so the immediately-following `$doing_cron_transient !==
// $doing_wp_cron` check fails against whatever (if anything) is already
// stored, and wp-cron.php returns instantly having run nothing. That
// produced a convincing but wrong "0 drained" result even fully unarmed
// -- exactly the class of false result this ticket's evidence pipeline
// exists to catch. A bare GET with no query string is what an actual
// unauthenticated client (a browser, `curl`, a scanner) sends, and takes
// the `if` branch that both sets and matches its own lock.
// site_url(), not a hand-built string: WP_SITEURL (docker/Dockerfile)
// already carries the `/wp` prefix core lives under (issue #29), so this
// resolves to ".../wp/wp-cron.php" without this script needing to know
// that prefix itself.
//
// wpcas_internal_loopback_rewrite() (lib/internal-loopback.php) then
// swaps the connection target from "localhost:$STACK_PORT" -- correct
// for a real external client, but unreachable from *inside* this
// php-fpm container now that nginx is a separate Compose service -- for
// nginx's own service name, while keeping the original host:port as an
// explicit Host header below. See that file's own docblock for why.
$loopback = wpcas_internal_loopback_rewrite( site_url( 'wp-cron.php' ) );
$url      = $loopback['url'];

fwrite(
	STDERR,
	sprintf(
		"Preflight passed. Issuing unauthenticated GET %s (Host: %s) against %d pending action(s)...\n",
		$url,
		$loopback['host_header'],
		$pending_before
	)
);

// --- Issue the request -----------------------------------------------------
//
// file_get_contents() over the http:// stream wrapper, not curl: it's a
// genuinely separate TCP request/response to the running server (no
// different from a real client's GET), and needs no extra PHP extension
// beyond what this image already has (allow_url_fopen is on by default).
// ignore_errors: true so a non-2xx response (the whole point of the
// "armed" scenario -- a 403) is still captured as a normal response
// instead of file_get_contents() emitting a warning and returning false.
$started_at = gmdate( 'c' );
$start_time = microtime( true );

$context = stream_context_create(
	array(
		'http' => array(
			'method'        => 'GET',
			'header'        => "Host: {$loopback['host_header']}\r\n",
			'timeout'       => 120,
			'ignore_errors' => true,
		),
	)
);

$body = @file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- see ignore_errors above; failure is read from $body/$http_response_header, not a warning.

$elapsed_seconds = microtime( true ) - $start_time;
$finished_at     = gmdate( 'c' );

/** @var string[] $http_response_header Set by the http:// stream wrapper above; absent entirely if the connection itself failed. */
$response_headers = $http_response_header ?? array();
$http_status       = wpcas_http_parse_status_code( $response_headers );

if ( array() === $response_headers ) {
	fwrite( STDERR, "--- no HTTP response received (connection-level failure) ---\n" );
} else {
	fwrite(
		STDERR,
		sprintf(
			"--- HTTP response ---\nstatus: %s\nbody length: %d bytes\n",
			null === $http_status ? '(none parsed)' : (string) $http_status,
			strlen( false === $body ? '' : $body )
		)
	);
}

// --- Capture post-run state ------------------------------------------------
//
// Issue #29: under PHP-FPM, core's wp-cron.php calls
// fastcgi_finish_request() before it does anything else, which flushes
// and closes the response to *this* client -- the request above can
// return in a few milliseconds even when wp-cron.php goes on to actually
// drain the whole queue afterwards, in the same worker, after the
// connection is already closed. Reading pending_after immediately (as
// this script did under the old built-in server, which had no such call
// and always finished draining before responding at all) would race that
// still-running background work and read a false "0 drained" on every
// unarmed run -- not because nothing happened, but because nothing had
// happened *yet* by the time this script checked. wpcas_probe_poll_until_settled()
// (lib/probe.php, already used by measure-admin-page-load.php and
// measure-manual-run.php for the same reason on their own fire-and-forget
// paths) waits for the pending count to actually stabilise instead.
$poll = wpcas_probe_poll_until_settled( $pending_before, 30 );

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
$cron_in_progress_after = wpcas_probe_cron_in_progress();

// Also surfaced in the canonical result record itself (`cron_in_progress_after`,
// see lib/result-record.php's schema_version 2 -> 3 note) -- logged here
// too purely for a human watching STDERR live, not as the only place this
// fact is captured. The armed scenario's acceptance criterion ("no
// cron-in-progress transient left behind") must be provable from the
// committed JSON alone, not from an uncommitted follow-up preflight taken
// on trust.
fwrite(
	STDERR,
	sprintf( "cron-in-progress (\"doing_cron\") transient after this request: %s\n", $cron_in_progress_after ? 'true' : 'false' )
);

$record = wpcas_result_record_build(
	array(
		'control'                => $control,
		// Neither field applies to an HTTP-vector row -- see the module
		// docblock on lib/result-record.php.
		'command_argv'           => null,
		'command_exit_code'      => null,
		'http_status'            => $http_status,
		'started_at'             => $started_at,
		'finished_at'            => $finished_at,
		'elapsed_seconds'        => $elapsed_seconds,
		'preflight'              => $preflight['snapshot'],
		'pending_before'         => $pending_before,
		'pending_after'          => $pending_after,
		'log_messages'           => $log_messages,
		'probe_records'          => $probe_records,
		'cron_in_progress_after' => $cron_in_progress_after,
	)
);

fwrite(
	STDERR,
	sprintf(
		"Control '%s' finished: %d -> %d pending (drained %d) in %.3fs.\n",
		$control,
		$pending_before,
		$pending_after,
		$record['outcome']['drained'],
		$elapsed_seconds
	)
);

// The result record, and only the result record, goes to STDOUT -- see
// bin/stack's `measure-http` subcommand, which redirects this script's
// stdout straight to a file under results/.
echo wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
