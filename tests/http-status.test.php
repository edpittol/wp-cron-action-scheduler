<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/http-status.php.
 *
 * No WordPress, no container, no test framework -- same reasoning as
 * tests/preflight-assertions.test.php and tests/result-record.test.php:
 * this is pure input -> output logic (parsing a status code out of
 * `$http_response_header`-shaped header lines), so it's cheap to pin down
 * with plain `assert()`-style checks before wiring it into
 * docker/wp-cli/measure-http.php (issue #5), which cannot itself be
 * exercised without a running container.
 *
 * Run: php tests/http-status.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/http-status.php';

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

// A normal single-response header block: status is the obvious 200.
wpcas_test_assert_same(
	'simple 200',
	200,
	wpcas_http_parse_status_code(
		array(
			'HTTP/1.1 200 OK',
			'Content-Type: text/html; charset=UTF-8',
		)
	),
	$failures
);

// The guard's own response: no headers beyond the status line at all,
// exactly what `http_response_code( 403 ); exit;` produces.
wpcas_test_assert_same(
	'bare 403',
	403,
	wpcas_http_parse_status_code( array( 'HTTP/1.1 403 Forbidden' ) ),
	$failures
);

// A redirected request reports one "HTTP/..." line per hop in
// $http_response_header -- the *last* one is the status that actually
// reached the client, not the first.
wpcas_test_assert_same(
	'redirect chain uses the final status',
	200,
	wpcas_http_parse_status_code(
		array(
			'HTTP/1.1 301 Moved Permanently',
			'Location: http://example.test/wp-cron.php',
			'HTTP/1.1 200 OK',
			'Content-Type: text/html; charset=UTF-8',
		)
	),
	$failures
);

// No headers at all (e.g. the request never got a response) -- must not
// fabricate a status, must not warn/fatal either.
wpcas_test_assert_same( 'empty headers', null, wpcas_http_parse_status_code( array() ), $failures );

// A header block with no HTTP/ status line in it at all.
wpcas_test_assert_same(
	'no status line',
	null,
	wpcas_http_parse_status_code( array( 'Content-Type: text/plain' ) ),
	$failures
);

// HTTP/2 responses format the status line without a reason phrase.
wpcas_test_assert_same(
	'HTTP/2 status line, no reason phrase',
	403,
	wpcas_http_parse_status_code( array( 'HTTP/2 403' ) ),
	$failures
);

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (" . 'wpcas_http_parse_status_code' . ")\n";
exit( 0 );
