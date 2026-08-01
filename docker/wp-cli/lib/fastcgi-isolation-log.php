<?php

declare( strict_types=1 );

/**
 * Pure parsing logic for the server-side log the two isolation proof
 * files write to (docker/fastcgi-isolation/flush-then-status.php and
 * status-then-flush.php, issue #34). Deliberately free of any
 * WordPress/$wpdb/WP-CLI/filesystem dependency, so it can be exercised
 * with plain `php`, no container required -- see
 * tests/fastcgi-isolation-log.test.php. The effectful part (reading the
 * log file itself off disk) lives in
 * docker/wp-cli/measure-fastcgi-isolation.php, which calls into this
 * file with whatever it read.
 *
 * The log is one JSON object per line, e.g.:
 *
 *   {"file":"flush-then-status","post_flush_status":403,"timestamp":"...","pid":123}
 *
 * written by each proof file's own file_put_contents(..., FILE_APPEND).
 * Because both files share one log and the measurement script drives them
 * sequentially -- one request, read back, then the next -- the *last*
 * line matching a given file's own key is always the entry that exact
 * request just wrote, the same "last wins" reasoning
 * docker/wp-cli/lib/http-status.php already applies to a redirect
 * chain's status lines. This stops being safe the moment two isolation
 * requests could be in flight concurrently (their lines could interleave
 * and "last" would no longer mean "this request's own"); nothing in this
 * ticket's scope does that -- see the request-id correlation issue (#33)
 * for the general solution this file deliberately does not attempt.
 */

/**
 * Extracts the `post_flush_status` from the last log line for the given
 * file key, ignoring anything that doesn't parse as JSON or doesn't carry
 * a matching `file` field -- a stray/malformed line must not be
 * misreported as this file's own status.
 *
 * Returns null (never fabricates a status) when no matching line exists.
 */
function wpcas_fastcgi_isolation_extract_last_status( string $log_contents, string $file ): ?int {
	if ( '' === trim( $log_contents ) ) {
		return null;
	}

	$status = null;

	foreach ( preg_split( '/\r\n|\r|\n/', $log_contents ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}

		$decoded = json_decode( $line, true );

		if ( ! is_array( $decoded ) ) {
			continue;
		}

		if ( ! isset( $decoded['file'], $decoded['post_flush_status'] ) ) {
			continue;
		}

		if ( $decoded['file'] !== $file ) {
			continue;
		}

		$status = (int) $decoded['post_flush_status'];
	}

	return $status;
}
