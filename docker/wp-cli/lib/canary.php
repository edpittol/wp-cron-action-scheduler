<?php

declare( strict_types=1 );

/**
 * Pure parsing logic for the section-3 canary guard's log output (issue
 * #6). Deliberately free of any WordPress/$wpdb/WP-CLI/filesystem
 * dependency, so it can be exercised with plain `php`, no container or WP
 * bootstrap required -- see tests/canary.test.php. All the effectful
 * parts (locating PHP's configured error_log destination, reading it
 * before/after a request, verifying it is actually writable rather than
 * assumed) live in docker/wp-cli/measure-async-ajax.php instead, which
 * calls into this file.
 *
 * The canary guard (docker/mu-plugins-available/30-log-non-cli-canary.php)
 * writes lines shaped like:
 *
 *   [cron-guard] queue run outside CLI: sapi=cli-server uri=/wp-admin/admin-ajax.php?action=as_async_request_queue_runner ip=127.0.0.1
 *
 * into whatever PHP's error_log directive points at, alongside anything
 * else PHP itself might log there (deprecation notices, other warnings).
 * This file's job is picking the canary's own lines back out of that
 * mixed content -- never assuming the log holds nothing else.
 */

const WPCAS_CANARY_LOG_MARKER = '[cron-guard] queue run outside CLI:';

/**
 * Extracts every canary line from a raw log excerpt, in the order they
 * appear. Lines that don't carry the canary's own marker (any other
 * error_log content, blank lines) are ignored rather than misreported as
 * a canary firing.
 *
 * @return string[]
 */
function wpcas_canary_extract_lines( string $log_contents ): array {
	if ( '' === trim( $log_contents ) ) {
		return array();
	}

	$lines   = preg_split( '/\r\n|\r|\n/', $log_contents );
	$matches = array();

	foreach ( $lines as $line ) {
		if ( false !== strpos( $line, WPCAS_CANARY_LOG_MARKER ) ) {
			$matches[] = $line;
		}
	}

	return $matches;
}

/**
 * Joins whatever canary lines were captured into the single nullable
 * string the result record carries (see lib/result-record.php's
 * `canary_line` field) -- `null` when nothing fired, the line itself when
 * exactly one did, and newline-joined in the (unexpected, for this
 * ticket's controlled runs) case of more than one.
 */
function wpcas_canary_join_lines( array $lines ): ?string {
	if ( array() === $lines ) {
		return null;
	}

	return implode( "\n", $lines );
}
