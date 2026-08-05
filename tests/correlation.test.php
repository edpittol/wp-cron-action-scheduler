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

// Two sources disagreeing for the same request id -- the shape this
// correlator has to keep straight regardless of which source says what.
//
// NOTE, corrected against the running stack by issue #35: when issue #33
// wrote this case it read as "the process manager's line reports the real
// final status a guard set afterwards". That is NOT what PHP-FPM does. A
// measured run against the armed cron entry point produced status=200 in
// PHP-FPM's own access log as well -- the process manager records the
// FLUSHED status, exactly like nginx. The post-flush status needs its own,
// in-PHP source (see the third-source cases below), which is why issue #35
// added one instead of reading it out of this log. This case stays as a
// pure disagreement fixture, no longer as a claim about which log says
// what.
$pair_masked = wpcas_correlation_find_pair(
	'req-a',
	array( 'request_id=req-a status=200' ),
	array( 'request_id=req-a status=403' )
);
wpcas_test_assert_same( 'disagreeing pair: web_status', 200, $pair_masked['web_status'], $failures );
wpcas_test_assert_same( 'disagreeing pair: fpm_status', 403, $pair_masked['fpm_status'], $failures );

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

// --- The third source: the in-PHP post-flush status (issue #35) -----------

// The masking finding, as the three statuses one request actually produces:
// both server-side logs record the flushed 200 (measured -- see the
// corrected note above), while the in-PHP probe's own shutdown-time line
// records the 403 the guard set after the response was already closed.
// Three sources, one request id, and the disagreement IS the finding.
$masking = wpcas_correlation_find_pair(
	'req-masked',
	array( 'request_id=req-masked status=200' ),
	array( 'request_id=req-masked status=200' ),
	array( 'request_id=req-masked status=403 flushed=1 time=2026-08-04T17:00:00+00:00' )
);
wpcas_test_assert_same( 'masking: web_status is the client-observed 200', 200, $masking['web_status'], $failures );
wpcas_test_assert_same( 'masking: fpm_status is the flushed 200 too', 200, $masking['fpm_status'], $failures );
wpcas_test_assert_same( 'masking: post_flush_status is the unreadable 403', 403, $masking['post_flush_status'], $failures );

// A request that never reached PHP at all -- guard section 5's nginx-layer
// block (issue #36). nginx logged its own 403; there is no PHP process to
// have set anything afterwards, so the post-flush status is null. That null
// is a real observation about where the block happened, not missing
// evidence, and must never be backfilled from either access log.
$blocked_in_front = wpcas_correlation_find_pair(
	'req-nginx-blocked',
	array( 'request_id=req-nginx-blocked status=403' ),
	array(),
	array()
);
wpcas_test_assert_same( 'blocked in front of PHP: web_status 403', 403, $blocked_in_front['web_status'], $failures );
wpcas_test_assert_same( 'blocked in front of PHP: fpm_status null (never reached FPM)', null, $blocked_in_front['fpm_status'], $failures );
wpcas_test_assert_same( 'blocked in front of PHP: post_flush_status null (no PHP ran)', null, $blocked_in_front['post_flush_status'], $failures );

// Callers that pass no third source at all (any pre-#35 call site) report
// null for it rather than erroring.
$two_source_call = wpcas_correlation_find_pair( 'req-a', array( 'request_id=req-a status=200' ), array( 'request_id=req-a status=200' ) );
wpcas_test_assert_same( 'two-source call: post_flush_status defaults to null', null, $two_source_call['post_flush_status'], $failures );

// The probe's own line carries extra tokens (flushed=, time=) after the two
// the parser joins on -- proof the shared parser reads this source's shape
// as-is, with no source-specific branch.
$probe_line_parsed = wpcas_correlation_parse_line( 'request_id=req-x status=403 flushed=1 time=2026-08-04T17:00:00+00:00' );
wpcas_test_assert_same( 'probe line: request_id parsed', 'req-x', $probe_line_parsed['request_id'], $failures );
wpcas_test_assert_same( 'probe line: status parsed', 403, $probe_line_parsed['status'], $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (wpcas_correlation_parse_line / wpcas_correlation_index_by_request_id / wpcas_correlation_find_pair)\n";
exit( 0 );
