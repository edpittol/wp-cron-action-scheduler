<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/guard-block.php.
 *
 * No WordPress, no container, no test framework -- same split as
 * tests/canary.test.php and tests/result-record.test.php: the pure
 * parsing logic lives apart from the effectful log-reading glue in
 * docker/wp-cli/measure-http.php so it's cheap to pin down with plain
 * `assert()` calls.
 *
 * Run: php tests/guard-block.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/guard-block.php';

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

// --- wpcas_guard_block_extract_lines() -----------------------------------

$one_line = '[cron-guard] http entry point blocked post-flush: status=403';
wpcas_test_assert_same( 'single line: extracted', array( $one_line ), wpcas_guard_block_extract_lines( $one_line ), $failures );

// Empty/whitespace-only content: the guard never fired, not an error.
wpcas_test_assert_same( 'empty string: no lines', array(), wpcas_guard_block_extract_lines( '' ), $failures );
wpcas_test_assert_same( 'whitespace-only: no lines', array(), wpcas_guard_block_extract_lines( "  \n\n  " ), $failures );

// Content mixed in with an unrelated line sharing the same file -- the
// extraction must pick out only the marked line and leave the rest alone.
$mixed = implode(
	"\n",
	array(
		'some unrelated line that happens to share this file',
		$one_line,
	)
);
wpcas_test_assert_same( 'mixed content: extracts only the guard-block line', array( $one_line ), wpcas_guard_block_extract_lines( $mixed ), $failures );

// Two lines (e.g. a leftover from a previous, un-truncated run) -- both
// come back, in order; scoping to "this run only" is the caller's job
// (byte-offset before/after, same discipline as lib/canary.php's callers),
// not this parser's.
$second_line = '[cron-guard] http entry point blocked post-flush: status=403';
$two_lines   = $one_line . "\n" . $second_line;
wpcas_test_assert_same( 'two lines: both extracted, in order', array( $one_line, $second_line ), wpcas_guard_block_extract_lines( $two_lines ), $failures );

// A line that merely mentions "cron-guard" without the exact marker (e.g.
// the section-3 canary's own line) must not be picked up.
$unrelated_cron_guard_text = '[cron-guard] queue run outside CLI: sapi=fpm-fcgi uri=/wp/wp-cron.php ip=127.0.0.1';
wpcas_test_assert_same( 'unrelated cron-guard text: not extracted', array(), wpcas_guard_block_extract_lines( $unrelated_cron_guard_text ), $failures );

// --- wpcas_guard_block_join_lines() --------------------------------------

wpcas_test_assert_same( 'join: no lines -> null', null, wpcas_guard_block_join_lines( array() ), $failures );
wpcas_test_assert_same( 'join: one line -> itself', $one_line, wpcas_guard_block_join_lines( array( $one_line ) ), $failures );
wpcas_test_assert_same( 'join: two lines -> newline-joined', $one_line . "\n" . $second_line, wpcas_guard_block_join_lines( array( $one_line, $second_line ) ), $failures );

// --- wpcas_guard_block_parse_status() -------------------------------------

wpcas_test_assert_same( 'parse: null input -> null', null, wpcas_guard_block_parse_status( null ), $failures );
wpcas_test_assert_same( 'parse: normal line -> 403', 403, wpcas_guard_block_parse_status( $one_line ), $failures );
wpcas_test_assert_same( 'parse: no status= in line -> null, never guessed', null, wpcas_guard_block_parse_status( '[cron-guard] http entry point blocked post-flush: (malformed)' ), $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (wpcas_guard_block_extract_lines / wpcas_guard_block_join_lines / wpcas_guard_block_parse_status)\n";
exit( 0 );
