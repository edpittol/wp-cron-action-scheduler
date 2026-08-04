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
 * Extracts the whole last log entry for the given file key, ignoring
 * anything that doesn't parse as JSON or doesn't carry a matching `file`
 * field -- a stray/malformed line must not be misreported as this file's
 * own record.
 *
 * Issue #35 widened this from "the post_flush_status int" to the entry
 * itself, because one status is no longer the whole observation: each proof
 * file now logs the status it ATTEMPTED, what the setting call RETURNED,
 * and the status actually in effect AFTERWARDS (see
 * docker/fastcgi-isolation/flush-then-status.php's docblock for why a
 * hardcoded 403 was not good enough). `attempted_status`/`set_call_returned`
 * are read with `??` rather than required, so a line written by an older
 * build of those files still yields its status instead of being discarded
 * as malformed.
 *
 * Returns null (never fabricates anything) when no matching line exists.
 *
 * @return array{post_flush_status: int, attempted_status: int|null, set_call_returned: int|bool|null}|null
 */
function wpcas_fastcgi_isolation_extract_last_entry( string $log_contents, string $file ): ?array {
	if ( '' === trim( $log_contents ) ) {
		return null;
	}

	$entry = null;

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

		$entry = array(
			'post_flush_status' => (int) $decoded['post_flush_status'],
			'attempted_status'  => isset( $decoded['attempted_status'] ) ? (int) $decoded['attempted_status'] : null,
			'set_call_returned' => $decoded['set_call_returned'] ?? null,
		);
	}

	return $entry;
}

/**
 * How many entries the log currently holds for the given file key.
 *
 * Exists so a caller can tell a NEW entry from the one an earlier run left
 * behind (issue #35): the log is append-only and lives for the container's
 * lifetime, so "the last line for this key" is only this request's line if
 * this request actually wrote one. docker/wp-cli/measure-fastcgi-isolation.php
 * counts before issuing its request and waits for the count to grow --
 * without that, its bounded wait would be satisfied instantly by a stale
 * entry and would report a previous run's facts as this one's.
 */
function wpcas_fastcgi_isolation_count_entries( string $log_contents, string $file ): int {
	if ( '' === trim( $log_contents ) ) {
		return 0;
	}

	$count = 0;

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

		++$count;
	}

	return $count;
}

/**
 * The post-flush status alone, for callers that only need that one field.
 * Kept as a thin wrapper over wpcas_fastcgi_isolation_extract_last_entry()
 * so there is still exactly one parser walking the log.
 */
function wpcas_fastcgi_isolation_extract_last_status( string $log_contents, string $file ): ?int {
	$entry = wpcas_fastcgi_isolation_extract_last_entry( $log_contents, $file );

	return null === $entry ? null : $entry['post_flush_status'];
}
