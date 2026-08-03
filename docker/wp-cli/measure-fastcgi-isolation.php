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
	$log_contents = is_file( WPCAS_FASTCGI_ISOLATION_LOG ) ? (string) file_get_contents( WPCAS_FASTCGI_ISOLATION_LOG ) : '';
	$post_flush_status = wpcas_fastcgi_isolation_extract_last_status( $log_contents, $key );

	fwrite(
		STDERR,
		sprintf(
			"  post-flush status (server-side record): %s\n",
			null === $post_flush_status ? '(none recorded)' : (string) $post_flush_status
		)
	);

	$files[ $key ] = array(
		'url'               => $url,
		'observable_status' => $observable_status,
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
