<?php

declare( strict_types=1 );

/**
 * Issue #34: the isolated, two-file proof that `fastcgi_finish_request()`
 * alone -- not WordPress, not Action Scheduler, not any mu-plugin -- is
 * what makes a PHP-FPM client see a status the server did not actually
 * settle on. This file is the half that reproduces the masking itself;
 * its sibling, status-then-flush.php, is the control that shows the
 * status pathway works and only the *ordering* differs.
 *
 * What this file does, in order:
 *
 *   1. Calls fastcgi_finish_request() with no status set beforehand, so
 *      PHP's implicit 200 is what flushes and closes the response to the
 *      client. This is exactly core's own wp-cron.php: it calls this same
 *      function before it defines the cron constant or loads mu-plugins,
 *      so whatever a guard section decides afterwards can never reach the
 *      client that already got its 200.
 *   2. Only after the client connection is closed, sets an observable
 *      status of 403 via http_response_code(). This cannot change what
 *      the client already received -- the call itself would normally warn
 *      ("headers already sent"), which is suppressed below since the
 *      warning is not the point being demonstrated; the DIVERGENCE is.
 *   3. Records that post-flush 403 to a server-side log
 *      (WPCAS_FASTCGI_ISOLATION_LOG below) -- the only place this status
 *      is ever observable from. docker/wp-cli/measure-fastcgi-isolation.php
 *      reads it back and pairs it in one result record with the status
 *      the HTTP client actually saw.
 *
 * Deliberately web-reachable and independent of WordPress -- see this
 * repo's README ("Caveats") for why that's a documented trade-off, not an
 * oversight: a reader can convince themselves this divergence is caused
 * by fastcgi_finish_request() alone, without reading (or trusting) the
 * rest of this harness.
 */

const WPCAS_FASTCGI_ISOLATION_LOG = '/var/log/wpcas/fastcgi-isolation.log';

if ( ! function_exists( 'fastcgi_finish_request' ) ) {
	// Defensive only -- this stack always runs this file under PHP-FPM
	// (docker-compose.yml). Without the function this file exists to
	// prove, there is nothing left to demonstrate.
	http_response_code( 500 );
	echo "fastcgi_finish_request() is unavailable -- this proof requires PHP-FPM.\n";
	exit;
}

echo "This response is about to be flushed and closed as an implicit 200.\n";

// The masking call. Everything from here on runs after the client's
// connection is already closed.
fastcgi_finish_request();

// phpcs:ignore WordPress.PHP.NoSilencedErrors -- see the module docblock:
// calling this after the flush cannot reach the client and would
// otherwise emit "headers already sent", which is expected, not an error
// worth surfacing.
@http_response_code( 403 );

$entry = array(
	'file'              => 'flush-then-status',
	'post_flush_status' => 403,
	'timestamp'         => gmdate( 'c' ),
	'pid'               => getmypid(),
);

// Plain json_encode() -- this file is deliberately independent of
// WordPress (see the module docblock), so wp_json_encode() is not
// available here. The entry is a flat, string/int-only array, which
// json_encode() alone handles without any of wp_json_encode()'s extra
// behaviour (e.g. its unicode-escaping default) mattering.
file_put_contents( WPCAS_FASTCGI_ISOLATION_LOG, json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
