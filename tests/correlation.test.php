<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/correlation.php.
 *
 * No WordPress, no container, no live server -- same reasoning as
 * tests/http-status.test.php and tests/result-record.test.php: this is
 * pure input -> output logic (parsing and joining raw access log lines), so
 * it's cheap to pin down here before wiring it into
 * docker/wp-cli/measure-http.php (issue #33), which is the only caller that
 * can actually read real log files.
 *
 * Run: php tests/correlation.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/correlation.php';

$failures = array();

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function wpcas_test_assert_same( string $label, $expected, $actual, array &$failures ): void {
	if ( $expected !== $actual ) {
		$failures[] = sprintf(
			'%s: expected %s, got %s',
			$label,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
	}
}

// --- wpcas_correlation_parse_line() --------------------------------------

// nginx's `wpcas` log_format (docker/nginx/default.conf): request id first,
// status later in the line, other fields around them.
wpcas_test_assert_same(
	'nginx-shaped line: parses request_id and status',
	array( 'request_id' => 'abc123', 'status' => 200 ),
	wpcas_correlation_parse_line( 'request_id=abc123 status=200 time=2026-07-29T00:50:17+00:00' ),
	$failures
);

// PHP-FPM's access.format (docker/php-fpm/access-log.conf): same two
// tokens, different surrounding fields -- one parser handles both.
wpcas_test_assert_same(
	'php-fpm-shaped line: parses request_id and status',
	array( 'request_id' => 'abc123', 'status' => 403 ),
	wpcas_correlation_parse_line( '[29/Jul/2026:00:50:17 +0000] request_id=abc123 status=403 "GET /wp/wp-cron.php"' ),
	$failures
);

// nginx substitutes "-" for a header the request never sent -- e.g. any
// request this repo's own tooling didn't issue. Not a malformed line, just
// not one this correlator can join on.
wpcas_test_assert_same(
	'absent header ("-"): does not parse',
	null,
	wpcas_correlation_parse_line( 'request_id=- status=200' ),
	$failures
);

// No request_id token at all.
wpcas_test_assert_same(
	'no request_id token: does not parse',
	null,
	wpcas_correlation_parse_line( 'status=200 time=2026-07-29T00:50:17+00:00' ),
	$failures
);

// Blank line.
wpcas_test_assert_same( 'blank line: does not parse', null, wpcas_correlation_parse_line( '' ), $failures );

// --- wpcas_correlation_index_by_request_id() -----------------------------

$index = wpcas_correlation_index_by_request_id(
	array(
		'request_id=req-a status=200',
		'request_id=req-b status=403',
		'status=200', // No id -- discarded, not a fatal error.
	)
);
wpcas_test_assert_same( 'index: req-a', 200, $index['req-a'], $failures );
wpcas_test_assert_same( 'index: req-b', 403, $index['req-b'], $failures );
wpcas_test_assert_same( 'index: only two entries (unmatched line discarded)', 2, count( $index ), $failures );

// --- wpcas_correlation_find_pair() ---------------------------------------

// The headline case: one request, one line in each source, both agreeing.
$pair = wpcas_correlation_find_pair(
	'req-a',
	array( 'request_id=req-a status=200' ),
	array( 'request_id=req-a status=200' )
);
wpcas_test_assert_same( 'single pair: web_status', 200, $pair['web_status'], $failures );
wpcas_test_assert_same( 'single pair: fpm_status', 200, $pair['fpm_status'], $failures );

// The masking finding this ticket exists to make measurable: the web
// server's line reports what the client actually saw (200, already
// flushed via fastcgi_finish_request()), while the process manager's own
// line -- written after the PHP process actually finished -- reports the
// real final status a guard set afterwards. Two different, both-genuine
// statuses for the same request id.
$pair_masked = wpcas_correlation_find_pair(
	'req-a',
	array( 'request_id=req-a status=200' ),
	array( 'request_id=req-a status=403' )
);
wpcas_test_assert_same( 'masked pair: web_status is the client-observed 200', 200, $pair_masked['web_status'], $failures );
wpcas_test_assert_same( 'masked pair: fpm_status is the server-recorded 403', 403, $pair_masked['fpm_status'], $failures );

// This ticket's acceptance criterion in its sharpest form: correlation must
// pick the right pair when two requests' lines interleave across the two
// logs, not merely take the nearest or next line. Request A's PHP-FPM
// worker is slower here, so the *order* the two sources were written in
// disagrees -- nginx logs A then B, PHP-FPM logs B then A. A "nearest
// line"/positional join would pair A's web line with B's process-manager
// line (both first in their list); only a join keyed on the id itself gets
// this right.
$web_lines_interleaved = array(
	'request_id=req-a status=200', // A's web line, written first.
	'request_id=req-b status=200', // B's web line, written second.
);
$fpm_lines_interleaved = array(
	'request_id=req-b status=200', // B's process-manager line, written first -- B was faster.
	'request_id=req-a status=403', // A's process-manager line, written second -- A was slower, and masked.
);

$pair_a = wpcas_correlation_find_pair( 'req-a', $web_lines_interleaved, $fpm_lines_interleaved );
wpcas_test_assert_same( 'interleaved: request A web_status', 200, $pair_a['web_status'], $failures );
wpcas_test_assert_same( 'interleaved: request A fpm_status (not B\'s 200)', 403, $pair_a['fpm_status'], $failures );

$pair_b = wpcas_correlation_find_pair( 'req-b', $web_lines_interleaved, $fpm_lines_interleaved );
wpcas_test_assert_same( 'interleaved: request B web_status', 200, $pair_b['web_status'], $failures );
wpcas_test_assert_same( 'interleaved: request B fpm_status (not A\'s 403)', 200, $pair_b['fpm_status'], $failures );

// A request id present in one source but not the other -- e.g. the process
// manager's access log wasn't enabled for this run, or the line hasn't
// been flushed to disk yet -- reports that side as null rather than
// fabricating a value or falling back to the other source.
$pair_missing_fpm = wpcas_correlation_find_pair( 'req-c', array( 'request_id=req-c status=200' ), array() );
wpcas_test_assert_same( 'missing from one source: web_status present', 200, $pair_missing_fpm['web_status'], $failures );
wpcas_test_assert_same( 'missing from one source: fpm_status is null, not fabricated', null, $pair_missing_fpm['fpm_status'], $failures );

// A request id absent from both sources entirely.
$pair_unknown = wpcas_correlation_find_pair( 'never-logged', array(), array() );
wpcas_test_assert_same( 'absent from both: web_status is null', null, $pair_unknown['web_status'], $failures );
wpcas_test_assert_same( 'absent from both: fpm_status is null', null, $pair_unknown['fpm_status'], $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (wpcas_correlation_parse_line / wpcas_correlation_index_by_request_id / wpcas_correlation_find_pair)\n";
exit( 0 );
