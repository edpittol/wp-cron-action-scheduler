<?php

declare( strict_types=1 );

/**
 * Pure parsing logic for docker/wp-cli/measure-http.php (issue #5).
 *
 * Deliberately free of any WordPress/$wpdb/WP-CLI/network dependency, so
 * it can be exercised with plain `php`, no container, no live request --
 * see tests/http-status.test.php. Same split as lib/preflight-assertions.php
 * vs. lib/probe.php, and lib/result-record.php vs. measure.php: the
 * network I/O (issuing the actual unauthenticated GET to wp-cron.php via
 * `file_get_contents()` and capturing `$http_response_header`) lives in
 * measure-http.php, which calls into this file with whatever header
 * lines PHP handed back.
 *
 * Central point this exists to make explicit: the status line is parsed
 * and recorded, but per the ticket ("status codes are recorded but never
 * used to determine outcome") nothing in this codebase's outcome logic
 * (wpcas_result_compute_outcome() in lib/result-record.php) ever consumes
 * this function's return value -- it flows into the result record's
 * `http_status` field purely as a recorded fact.
 */

/**
 * Extracts the HTTP status code from a set of raw response header lines,
 * in the shape PHP's `$http_response_header` superglobal-like variable
 * populates after a `file_get_contents()`/stream-wrapper HTTP request.
 *
 * A redirected request appends one full set of headers per hop -- e.g. a
 * "HTTP/1.1 301 ..." block followed by a "HTTP/1.1 200 ..." block -- so
 * this deliberately returns the *last* status line's code, the one that
 * actually reached the client, not the first.
 *
 * Returns null (never fabricates a code) when no line matches the
 * "HTTP/<version> <code>" shape at all -- e.g. no response was received,
 * or the header block is empty/malformed.
 *
 * @param string[] $header_lines
 */
function wpcas_http_parse_status_code( array $header_lines ): ?int {
	$status = null;

	foreach ( $header_lines as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $matches ) ) {
			$status = (int) $matches[1];
		}
	}

	return $status;
}
