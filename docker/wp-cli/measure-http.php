<?php

declare( strict_types=1 );

/**
 * Measures the HTTP vector (issue #5): an unauthenticated GET to
 * wp-cron.php, issued as a genuinely separate HTTP request against this
 * stack's own running PHP built-in server -- not a same-process
 * simulation of one. Same evidence pipeline as docker/wp-cli/measure.php
 * (issue #4): preflight first, refuse to measure on failure, then build
 * and write the canonical result record (docker/wp-cli/lib/result-record.php,
 * schema_version 2).
 *
 * This is the HTTP-vector row shape that file's own docblock reserves for
 * #5/#6/#7: `command` is always null (no WP-CLI command runs here) and
 * `http_status` is populated with whatever this request's status line
 * said.
 *
 * On *this* stack (PHP's built-in server via `php -S`, no PHP-FPM, no
 * `fastcgi_finish_request()`) that status line is genuinely observable by
 * the client -- unlike behind PHP-FPM, where core's wp-cron.php flushes
 * and closes the response as 200 before any mu-plugin code (including the
 * guard this measures) ever runs. See docker/mu-plugins-available/
 * 10-block-http-cron.php's own docblock, and this ticket's `## Decisions`,
 * for that deviation. Recorded here regardless, per the ticket
 * ("status codes are recorded but never used to determine outcome") --
 * wpcas_result_compute_outcome() never takes it as an input; outcome is
 * always pending-count-before/after plus the probe's own execution log.
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

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$control = $args[0] ?? 'http-wp-cron';

// --- Preflight (same facts/evaluation as preflight.php / measure.php) ---

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

// STACK_PORT is exported into this container's environment by
// docker-compose.yml (see bin/stack, which derives it and passes it
// through). The built-in server (entrypoint.sh) listens on
// 0.0.0.0:$STACK_PORT, so a request to 127.0.0.1 on the same port from
// inside this same container reaches that exact running server -- the
// same one a real external client would.
$port = getenv( 'STACK_PORT' );
if ( false === $port || '' === $port ) {
	WP_CLI::error( 'STACK_PORT is not set in this container\'s environment -- cannot build the request URL.' );
}

// doing_wp_cron query arg is what core's own spawn_cron() appends; not
// required for wp-cron.php to run, but included so this request looks
// exactly like the one WordPress would send itself, not a hand-shaped
// approximation of it.
$url = sprintf( 'http://127.0.0.1:%s/wp-cron.php?doing_wp_cron=%s', $port, urlencode( (string) microtime( true ) ) );

fwrite(
	STDERR,
	sprintf(
		"Preflight passed. Issuing unauthenticated GET %s against %d pending action(s)...\n",
		$url,
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

$pending_after     = wpcas_probe_pending_count();
$log_messages      = wpcas_probe_log_messages_for_actions( $action_ids );
$probe_records     = wpcas_probe_execution_log_entries();
$cron_in_progress_after = wpcas_probe_cron_in_progress();

// Diagnostic only -- deliberately NOT part of the canonical result record
// (docker/wp-cli/lib/result-record.php's schema is fixed at
// schema_version 2 for this ticket series; see its own module docblock).
// The armed scenario's acceptance criterion ("no cron-in-progress
// transient left behind") is verified by this line plus a
// `bin/stack preflight` run immediately afterwards (which asserts
// cron_in_progress === false itself) -- not by adding a field here.
fwrite(
	STDERR,
	sprintf( "cron-in-progress (\"doing_cron\") transient after this request: %s\n", $cron_in_progress_after ? 'true' : 'false' )
);

$record = wpcas_result_record_build(
	array(
		'control'           => $control,
		// Neither field applies to an HTTP-vector row -- see the module
		// docblock on lib/result-record.php.
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
