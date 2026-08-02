<?php

declare( strict_types=1 );

/**
 * Pure parsing logic for guard section 1's post-flush self-report (issue
 * #35). Deliberately free of any WordPress/$wpdb/WP-CLI/filesystem
 * dependency, so it can be exercised with plain `php`, no container or WP
 * bootstrap required -- see tests/guard-block.test.php. All the effectful
 * parts (locating the log file, verifying it is actually writable rather
 * than assumed, reading it before/after a request) live in
 * docker/wp-cli/measure-http.php, the same split as lib/canary.php vs. the
 * measure-*.php scripts that call into it.
 *
 * The guard (docker/mu-plugins-available/10-block-http-cron.php) writes a
 * line shaped like:
 *
 *   [cron-guard] http entry point blocked post-flush: status=403
 *
 * into /var/log/wpcas/guard-block.log -- a destination distinct from the
 * section-3 canary's (lib/canary.php), and from either access log
 * lib/correlation.php reads, precisely because those all reflect the
 * response as observed after core's wp-cron.php has already called
 * fastcgi_finish_request() (see the guard file's own docblock): this log
 * exists to carry the one fact that flush provably hides everywhere else
 * -- the literal status this guard's own `http_response_code()` call set,
 * from inside the same process that set it.
 */

const WPCAS_GUARD_BLOCK_LOG_MARKER = '[cron-guard] http entry point blocked post-flush:';

/**
 * Extracts every guard-block line from a raw log excerpt, in the order
 * they appear. Lines that don't carry the guard's own marker (any other
 * content sharing the file, blank lines) are ignored rather than
 * misreported as a block having happened.
 *
 * @return string[]
 */
function wpcas_guard_block_extract_lines( string $log_contents ): array {
	if ( '' === trim( $log_contents ) ) {
		return array();
	}

	$lines   = preg_split( '/\r\n|\r|\n/', $log_contents );
	$matches = array();

	foreach ( $lines as $line ) {
		if ( false !== strpos( $line, WPCAS_GUARD_BLOCK_LOG_MARKER ) ) {
			$matches[] = $line;
		}
	}

	return $matches;
}

/**
 * Joins whatever guard-block lines were captured into the single nullable
 * string the result record carries (see lib/result-record.php's
 * `post_flush_log_line` field) -- `null` when the guard never fired, the
 * line itself when exactly one did, and newline-joined in the (unexpected,
 * for this ticket's single-request runs) case of more than one.
 */
function wpcas_guard_block_join_lines( array $lines ): ?string {
	if ( array() === $lines ) {
		return null;
	}

	return implode( "\n", $lines );
}

/**
 * Parses the literal status the guard reported out of its own log line
 * (e.g. "status=403" -> 403). `null` when given `null` (the guard never
 * fired) or a line that, unexpectedly, carries no parseable status --
 * never guessed or defaulted to a particular value.
 */
function wpcas_guard_block_parse_status( ?string $log_line ): ?int {
	if ( null === $log_line ) {
		return null;
	}

	if ( 1 !== preg_match( '/status=(\d+)/', $log_line, $matches ) ) {
		return null;
	}

	return (int) $matches[1];
}
