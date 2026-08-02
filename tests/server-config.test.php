<?php

declare( strict_types=1 );

/**
 * Plain-PHP tests for docker/wp-cli/lib/server-config.php (issue #30).
 *
 * No WordPress, no container, no test framework -- these are pure
 * text-in/int-out parsers, so they're cheap to pin down with plain
 * `assert()` calls and `php tests/server-config.test.php`.
 *
 * Run: php tests/server-config.test.php
 */

require __DIR__ . '/../docker/wp-cli/lib/server-config.php';

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

// --- pm.max_children, read out of a realistic pool conf -------------------

$pool_conf = <<<'CONF'
[www]
pm = static
pm.max_children = 6
request_terminate_timeout = 35
CONF;

wpcas_test_assert_same(
	'pool_max_children: found',
	6,
	wpcas_server_config_parse_pool_max_children( $pool_conf ),
	$failures
);

wpcas_test_assert_same(
	'request_terminate_timeout: found',
	35,
	wpcas_server_config_parse_request_terminate_timeout( $pool_conf ),
	$failures
);

// A directive genuinely absent from the text (not merely commented out
// elsewhere) reports null, not a false zero or a fatal error -- a caller
// mistaking "absent" for "explicitly zero" is exactly the kind of silent
// misread this parser exists to avoid.
wpcas_test_assert_same(
	'pool_max_children: absent reports null',
	null,
	wpcas_server_config_parse_pool_max_children( "[www]\npm = dynamic\n" ),
	$failures
);

// A commented-out directive (the exact shape php-fpm.d/www.conf ships its
// own request_terminate_timeout default in, `;request_terminate_timeout = 0`)
// must not be mistaken for a live, explicit value -- this is the entire
// point of issue #30: an inherited/commented default reads as null (not
// pinned), never as the number that happens to follow the semicolon.
wpcas_test_assert_same(
	'request_terminate_timeout: commented-out reports null',
	null,
	wpcas_server_config_parse_request_terminate_timeout( "[www]\n;request_terminate_timeout = 0\n" ),
	$failures
);

// --- max_execution_time, read out of a realistic conf.d ini ----------------

wpcas_test_assert_same(
	'max_execution_time: found',
	30,
	wpcas_server_config_parse_max_execution_time( "max_execution_time = 30\n" ),
	$failures
);

wpcas_test_assert_same(
	'max_execution_time: absent reports null',
	null,
	wpcas_server_config_parse_max_execution_time( "memory_limit = 512M\n" ),
	$failures
);

// --- fastcgi_read_timeout, nginx's own `key value;` directive shape -------

wpcas_test_assert_same(
	'fastcgi_read_timeout: found',
	40,
	wpcas_server_config_parse_fastcgi_read_timeout( "fastcgi_read_timeout 40;\n" ),
	$failures
);

// Realistic surrounding context (comment lines, leading whitespace) doesn't
// throw the parser off.
wpcas_test_assert_same(
	'fastcgi_read_timeout: found amid comments/indentation',
	40,
	wpcas_server_config_parse_fastcgi_read_timeout( "# a comment\n    fastcgi_read_timeout 40;\n" ),
	$failures
);

wpcas_test_assert_same(
	'fastcgi_read_timeout: absent reports null',
	null,
	wpcas_server_config_parse_fastcgi_read_timeout( "fastcgi_pass php-fpm:9000;\n" ),
	$failures
);

if ( array() !== $failures ) {
	fwrite( STDERR, "FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	exit( 1 );
}

echo "OK (server-config parsers)\n";
exit( 0 );
