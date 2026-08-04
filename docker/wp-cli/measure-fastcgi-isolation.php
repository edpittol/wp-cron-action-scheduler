<?php

declare( strict_types=1 );

/**
 * Issue #34: drives both fastcgi_finish_request() isolation proof files
 * (docker/fastcgi-isolation/flush-then-status.php and
 * status-then-flush.php) with one unauthenticated GET each, and writes a
 * single result record carrying each file's observable status (what this
 * script's own HTTP client actually read) alongside its post-flush status
 * (what that file recorded server-side -- see
 * docker/wp-cli/lib/fastcgi-isolation-log.php).
 *
 * Deliberately plain `php`, not `wp eval-file`: unlike every other
 * measure-*.php script in this directory, this one needs no WordPress
 * bootstrap at all -- it never touches $wpdb, Action Scheduler, or the
 * probe queue, matching the two files it measures being independent of
 * WordPress themselves (issue #34's own acceptance criteria). Invoked via
 * `bin/stack measure-fastcgi-isolation`, which runs this file with plain
 * `php` inside the php-fpm container (not `wp eval-file`, for the same
 * reason) and captures its stdout (the JSON record, and nothing else --
 * see the module docblock on lib/result-record.php for the STDOUT/STDERR
 * split every measure-*.php script in this directory already follows) to
 * a file under results/.
 *
 * Reaches nginx by its Compose service name, not the published
 * `${STACK_PORT}`: this script runs inside the php-fpm container, a
 * separate Compose service with its own network namespace from nginx --
 * same reasoning as wpcas_internal_loopback_rewrite()
 * (lib/internal-loopback.php), just simpler here, since neither proof
 * file is a WordPress endpoint with a Host-header-sensitive identity to
 * preserve. A bare `http://nginx/...` URL is enough.
 *
 * Reads the isolation log directly off disk rather than over HTTP or
 * WP-CLI: this script already runs inside the same php-fpm container both
 * proof files execute in (nginx's `location ~ \.php$` block passes every
 * .php request to this same php-fpm service), so the log file
 * (WPCAS_FASTCGI_ISOLATION_LOG, shared with both proof files -- see their
 * own docblocks) is already on this script's own filesystem.
 *
 * Usage: php /opt/wpcas-tools/measure-fastcgi-isolation.php
 * (no arguments -- this is one fixed proof, not a per-scenario vector; see
 * bin/stack's own usage text.)
 */

require __DIR__ . '/lib/http-status.php';
require __DIR__ . '/lib/fastcgi-isolation-log.php';
require __DIR__ . '/lib/fastcgi-isolation-record.php';

const WPCAS_FASTCGI_ISOLATION_LOG      = '/var/log/wpcas/fastcgi-isolation.log';
// The document root itself -- both proof files are copied straight there
// by docker/Dockerfile, not into a subdirectory of their own (issue #34's
// own acceptance criteria: "two small web-reachable files at the
// document root").
const WPCAS_FASTCGI_ISOLATION_BASE_URL = 'http://nginx';

/**
 * The two proof files this script measures, keyed by the same `file`
 * value each one writes into its own log entry (see
 * lib/fastcgi-isolation-log.php) -- one request each, per issue #34's own
 * acceptance criteria.
 */
const WPCAS_FASTCGI_ISOLATION_FILES = array(
	'flush-then-status' => 'flush-then-status.php',
	'status-then-flush' => 'status-then-flush.php',
);

/**
 * Issues one GET and returns the observable status this script's own HTTP
 * client read -- never a status inferred from anything server-side.
 *
 * ignore_errors: true so a non-2xx response (the control's own 403) comes
 * back as a normal response instead of file_get_contents() emitting a
 * warning and returning false -- same discipline as measure-http.php.
 */
function wpcas_fastcgi_isolation_request( string $url ): ?int {
	$context = stream_context_create(
		array(
			'http' => array(
				'method'        => 'GET',
				'timeout'       => 30,
				'ignore_errors' => true,
			),
		)
	);

	@file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is read from $http_response_header below, not a warning.

	/** @var string[] $http_response_header Set by the http:// stream wrapper above; absent entirely if the connection itself failed. */
	$headers = $http_response_header ?? array();

	return wpcas_http_parse_status_code( $headers );
}

$files = array();

foreach ( WPCAS_FASTCGI_ISOLATION_FILES as $key => $filename ) {
	$url = WPCAS_FASTCGI_ISOLATION_BASE_URL . '/' . $filename;

	fwrite( STDERR, "Requesting {$url}...\n" );

	// Counted BEFORE the request (issue #35): the log is append-only and
	// outlives a single run, so this is what lets the bounded wait below
	// distinguish this request's own entry from one an earlier run left
	// behind, instead of being satisfied instantly by a stale line.
	$entries_before = wpcas_fastcgi_isolation_count_entries(
		is_file( WPCAS_FASTCGI_ISOLATION_LOG ) ? (string) file_get_contents( WPCAS_FASTCGI_ISOLATION_LOG ) : '',
		$key
	);

	$observable_status = wpcas_fastcgi_isolation_request( $url );

	fwrite(
		STDERR,
		sprintf(
			"  observable status: %s\n",
			null === $observable_status ? '(none parsed)' : (string) $observable_status
		)
	);

	// Read fresh after every request, before issuing the next one -- see
	// lib/fastcgi-isolation-log.php's own docblock for why "last line for
	// this key" is safe here specifically because these two requests run
	// strictly sequentially, one at a time.
	//
	// Polled with a bounded wait, not read once (fixed while re-measuring
	// this proof for issue #35, after a run recorded "(none recorded)" for
	// the flushed file): flush-then-status.php writes its log line AFTER
	// fastcgi_finish_request() has already closed the response, so this
	// script can hold the finished response in hand before that write has
	// happened. Reading once loses that race intermittently -- and loses it
	// precisely on the file whose whole point is doing work after the
	// response is gone. Same class of race, and the same bounded-wait
	// answer, as measure-http.php's settle poll. The bound still resolves
	// honestly: an entry that never appears is reported as null, never
	// substituted with the observable status.
	$deadline          = microtime( true ) + 5.0;
	$entry             = null;
	$post_flush_status = null;

	do {
		$log_contents = is_file( WPCAS_FASTCGI_ISOLATION_LOG ) ? (string) file_get_contents( WPCAS_FASTCGI_ISOLATION_LOG ) : '';

		// Only once a NEW entry for this key exists is the last one this
		// request's own -- see $entries_before above.
		if ( wpcas_fastcgi_isolation_count_entries( $log_contents, $key ) > $entries_before ) {
			$entry             = wpcas_fastcgi_isolation_extract_last_entry( $log_contents, $key );
			$post_flush_status = null === $entry ? null : $entry['post_flush_status'];
			break;
		}

		usleep( 100000 );
	} while ( microtime( true ) < $deadline );

	// Issue #35: what the file asked for, what PHP said about the asking,
	// and what was actually in effect afterwards -- three separate facts,
	// reported separately. The middle one is the whole reason the first two
	// can differ: `false` means PHP refused the status change outright.
	fwrite(
		STDERR,
		sprintf(
			"  attempted status: %s, set call returned: %s, post-flush status (read back server-side): %s\n",
			null === $entry || null === $entry['attempted_status'] ? '(none recorded)' : (string) $entry['attempted_status'],
			null === $entry ? '(none recorded)' : var_export( $entry['set_call_returned'], true ),
			null === $post_flush_status ? '(none recorded)' : (string) $post_flush_status
		)
	);

	$files[ $key ] = array(
		'url'               => $url,
		'observable_status' => $observable_status,
		'attempted_status'  => null === $entry ? null : $entry['attempted_status'],
		'set_call_returned' => null === $entry ? null : $entry['set_call_returned'],
		'post_flush_status' => $post_flush_status,
	);
}

$record = wpcas_fastcgi_isolation_record_build(
	array(
		'measured_at' => gmdate( 'c' ),
		'files'       => $files,
	)
);

// The result record, and only the result record, goes to STDOUT -- see
// bin/stack's `measure-fastcgi-isolation` subcommand, which redirects
// this script's stdout straight to a file under results/.
echo json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
