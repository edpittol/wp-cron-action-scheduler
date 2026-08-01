<?php

declare( strict_types=1 );

/**
 * Pure, WP-free parsers for the four config facts issue #30 requires the
 * preflight snapshot (and therefore every result record) to carry: the
 * PHP-FPM pool's worker ceiling, PHP's own execution-time ceiling,
 * PHP-FPM's own request-termination timeout, and nginx's upstream FastCGI
 * read timeout. All four are pinned explicitly in docker/Dockerfile and
 * docker/nginx/ -- this file's job is only to read the same bytes those
 * config files actually contain back into a preflight-shaped fact, never to
 * restate any of the four numbers as a second, separate literal (that would
 * be exactly the "two disagreeing numbers" issue #30's own acceptance
 * criteria rules out for the pool size, and the same trap applies equally
 * to the three timeouts).
 *
 * Deliberately free of any WordPress/$wpdb/WP-CLI/filesystem dependency --
 * every function here takes already-read config-file text as a plain
 * string, so this stays unit-testable with plain `php`, no container or WP
 * bootstrap required -- see tests/server-config.test.php. Same split as
 * docker/wp-cli/lib/preflight-assertions.php vs. docker/wp-cli/lib/probe.php:
 * the actual file reads (and the concrete, container-absolute paths those
 * files live at) are wpcas_probe_server_config()'s job, in
 * docker/wp-cli/lib/probe.php.
 *
 * `ini_get('max_execution_time')` is deliberately NOT used to read that one
 * back, even though it looks like the obvious API for it: PHP's CLI SAPI
 * (which every `wp eval-file` invocation -- preflight included -- always
 * runs as) unconditionally resets max_execution_time to 0 after ini
 * parsing, regardless of what any conf.d ini file sets it to -- found the
 * hard way while building this (confirmed against the base php:8.3-fpm
 * image directly: a conf.d ini setting `max_execution_time = 30` is
 * silently discarded under the `php` CLI binary, `ini_get()` still
 * reporting `0`). Calling ini_get() from within the very `wp eval-file`
 * process doing the reading would therefore always report the CLI SAPI's
 * own override, never the real ceiling the PHP-FPM request-handling workers
 * this value actually governs are running under. Reading the ini file's own
 * text back sidesteps that SAPI-specific override entirely.
 */

/**
 * Parses a single `directive = <integer>` line out of an ini-style or
 * php-fpm-pool-style config text. Both formats share the same
 * `key = value` shape for the directives this ticket cares about, so one
 * parser covers both; nginx's directive syntax (`key value;`, no `=`, a
 * trailing `;`) is different enough that it gets its own parser below.
 */
function wpcas_server_config_parse_ini_style_int( string $conf, string $directive ): ?int {
	if ( 1 === preg_match( '/^\s*' . preg_quote( $directive, '/' ) . '\s*=\s*(\d+)\s*$/m', $conf, $matches ) ) {
		return (int) $matches[1];
	}

	return null;
}

/**
 * The PHP-FPM pool's worker ceiling (docker/Dockerfile's
 * `zz-wpcas-pool.conf`, `pm.max_children`).
 */
function wpcas_server_config_parse_pool_max_children( string $pool_conf ): ?int {
	return wpcas_server_config_parse_ini_style_int( $pool_conf, 'pm.max_children' );
}

/**
 * PHP-FPM's own request-termination timeout, in seconds (same file,
 * `request_terminate_timeout`) -- the pool's hard kill for a worker that
 * has run longer than this many seconds, independent of PHP's own
 * max_execution_time (see wpcas_server_config_parse_max_execution_time()).
 */
function wpcas_server_config_parse_request_terminate_timeout( string $pool_conf ): ?int {
	return wpcas_server_config_parse_ini_style_int( $pool_conf, 'request_terminate_timeout' );
}

/**
 * PHP's own execution-time ceiling, in seconds (docker/Dockerfile's
 * `execution-time.ini`, `max_execution_time`).
 */
function wpcas_server_config_parse_max_execution_time( string $execution_time_ini ): ?int {
	return wpcas_server_config_parse_ini_style_int( $execution_time_ini, 'max_execution_time' );
}

/**
 * nginx's upstream FastCGI read timeout, in seconds
 * (docker/nginx/fastcgi-read-timeout.conf, `fastcgi_read_timeout <n>;`) --
 * nginx directive syntax, not the `key = value` ini shape the other three
 * parsers above share.
 */
function wpcas_server_config_parse_fastcgi_read_timeout( string $nginx_conf ): ?int {
	if ( 1 === preg_match( '/^\s*fastcgi_read_timeout\s+(\d+)\s*;/m', $nginx_conf, $matches ) ) {
		return (int) $matches[1];
	}

	return null;
}
