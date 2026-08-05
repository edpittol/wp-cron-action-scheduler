<?php

declare( strict_types=1 );

/**
 * Pure assembly logic for the fastcgi_finish_request() isolation proof's
 * result record (issue #34) -- the single record
 * docker/wp-cli/measure-fastcgi-isolation.php writes, carrying both proof
 * files' observable and post-flush statuses in one place. Deliberately
 * free of any WordPress/$wpdb/WP-CLI/network dependency, so it can be
 * exercised with plain `php`, no container -- see
 * tests/fastcgi-isolation-record.test.php.
 *
 * This is its own schema, separate from wpcas_result_record_build()
 * (docker/wp-cli/lib/result-record.php): that schema's whole shape is
 * built around a queue-draining control/vector (pending_count
 * before/after, Action Scheduler log messages, a preflight snapshot) --
 * none of which this isolation proof has or needs, since neither file
 * touches WordPress, Action Scheduler, or the probe queue at all. Reusing
 * that schema here would mean padding it with facts that don't exist for
 * this proof, or fields this proof needs that don't exist there. A
 * dedicated, smaller schema says exactly what this proof measures and
 * nothing else.
 */

/**
 * @param array{
 *     measured_at: string,
 *     files: array<string, array{url: string, observable_status: int|null, attempted_status?: int|null, set_call_returned?: int|bool|null, post_flush_status: int|null}>,
 * } $facts
 *
 * @return array<string, mixed>
 */
function wpcas_fastcgi_isolation_record_build( array $facts ): array {
	return array(
		// Bumped 1 -> 2 (issue #35): each file's entry gained
		// `attempted_status` and `set_call_returned`, so the record states
		// what the file asked for and what PHP did with the asking, instead
		// of presenting a hardcoded literal as an observed post-flush
		// status. `post_flush_status` now carries a value read back from
		// PHP rather than that literal -- same key, genuinely different
		// provenance, which is exactly what a version bump is for.
		'schema_version' => 2,
		'measured_at'    => $facts['measured_at'],
		'files'          => $facts['files'],
	);
}
