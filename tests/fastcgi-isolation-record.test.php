<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/fastcgi-isolation-record.php.
 *
 * No WordPress, no container, no test framework -- same discipline as
 * tests/result-record.test.php: pure assembly logic, exercised with
 * plain `assert()`-style checks.
 *
 * Run: php tests/fastcgi-isolation-record.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/fastcgi-isolation-record.php';

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

// The headline shape this ticket's own acceptance criteria describe: the
// control's observable status is 403, the flushed file's is 200, and both
// record 403 server-side.
$facts = array(
	'measured_at' => '2026-08-01T00:00:00+00:00',
	'files'       => array(
		'flush-then-status' => array(
			'url'               => 'http://nginx/fastcgi-isolation/flush-then-status.php',
			'observable_status' => 200,
			'post_flush_status' => 403,
		),
		'status-then-flush'  => array(
			'url'               => 'http://nginx/fastcgi-isolation/status-then-flush.php',
			'observable_status' => 403,
			'post_flush_status' => 403,
		),
	),
);

$record = wpcas_fastcgi_isolation_record_build( $facts );

wpcas_test_assert_same( 'schema_version is 1', 1, $record['schema_version'], $failures );
wpcas_test_assert_same( 'measured_at passes through unchanged', $facts['measured_at'], $record['measured_at'], $failures );
wpcas_test_assert_same( 'files passes through unchanged', $facts['files'], $record['files'], $failures );

// The two headline assertions issue #34's own acceptance criteria name
// explicitly, read back from the built record rather than the input
// facts, so this actually pins down what the record itself carries.
wpcas_test_assert_same( 'flushed file: observable status is 200', 200, $record['files']['flush-then-status']['observable_status'], $failures );
wpcas_test_assert_same( 'flushed file: post-flush status is 403', 403, $record['files']['flush-then-status']['post_flush_status'], $failures );
wpcas_test_assert_same( 'control: observable status is 403', 403, $record['files']['status-then-flush']['observable_status'], $failures );
wpcas_test_assert_same( 'control: post-flush status is 403', 403, $record['files']['status-then-flush']['post_flush_status'], $failures );

// A failed request (e.g. connection refused) must not fabricate a
// status -- null passes straight through, same as every other nullable
// status field this repo's other result records carry
// (lib/http-status.php's own docblock makes the same "never fabricate a
// code" promise).
$facts_with_failure = array(
	'measured_at' => '2026-08-01T00:00:00+00:00',
	'files'       => array(
		'flush-then-status' => array(
			'url'               => 'http://nginx/fastcgi-isolation/flush-then-status.php',
			'observable_status' => null,
			'post_flush_status' => null,
		),
	),
);
$record_with_failure = wpcas_fastcgi_isolation_record_build( $facts_with_failure );
wpcas_test_assert_same( 'a failed request: observable_status stays null', null, $record_with_failure['files']['flush-then-status']['observable_status'], $failures );
wpcas_test_assert_same( 'a failed request: post_flush_status stays null', null, $record_with_failure['files']['flush-then-status']['post_flush_status'], $failures );

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (wpcas_fastcgi_isolation_record_build)\n";
exit( 0 );
