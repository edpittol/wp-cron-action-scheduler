<?php

declare( strict_types=1 );

/**
 * Pure evaluation logic for the preflight gate (issue #2).
 *
 * Deliberately free of any WordPress/$wpdb/WP-CLI dependency, so it can
 * be exercised with plain `php`, no container or WP bootstrap required --
 * see tests/preflight-assertions.test.php. All the WP-aware fact-gathering
 * (querying Action Scheduler, $wpdb, transients, and -- issue #31 -- the
 * live WordPress/Action Scheduler versions and docker/composer.lock's own
 * resolved versions) lives in docker/wp-cli/lib/probe.php instead, which
 * calls into this file.
 *
 * Turns a set of already-gathered facts about the current site state into
 * a pass/fail verdict plus the machine-readable snapshot the ticket asks
 * preflight to emit "so later results can carry their own proof of
 * validity."
 *
 * @param array{
 *     pending_count: int,
 *     callback_attached: bool,
 *     cron_in_progress: bool,
 *     claims_count: int,
 *     pool_max_children: int|null,
 *     max_execution_time_seconds: int|null,
 *     request_terminate_timeout_seconds: int|null,
 *     fastcgi_read_timeout_seconds: int|null,
 *     wp_version: string,
 *     wp_version_lockfile: string,
 *     action_scheduler_version: string,
 *     action_scheduler_version_lockfile: string,
 * } $facts
 *
 * @return array{
 *     ok: bool,
 *     failures: string[],
 *     snapshot: array<string, mixed>,
 * }
 */
function wpcas_preflight_evaluate( array $facts ): array {
	$failures = array();

	// The single most convincing false result in this problem space: the
	// pending count fell to zero not because a real drain happened, but
	// because nothing was ever there (or already silently vanished).
	if ( $facts['pending_count'] <= 0 ) {
		$failures[] = sprintf( 'pending_count must be greater than zero, got %d', $facts['pending_count'] );
	}

	// The exact false result the ticket names explicitly: an action whose
	// hook has no attached callback completes as an instant no-op.
	if ( ! $facts['callback_attached'] ) {
		$failures[] = 'no callback is attached to the probe hook';
	}

	if ( $facts['cron_in_progress'] ) {
		$failures[] = 'the cron-in-progress transient ("doing_cron") is still set';
	}

	if ( 0 !== $facts['claims_count'] ) {
		$failures[] = sprintf( 'the claims table is not empty, found %d row(s)', $facts['claims_count'] );
	}

	// Issue #30: the worker pool ceiling and every relevant timeout must be
	// readable back from the running stack, not merely pinned somewhere a
	// reader has to go trust unread. A null here means
	// wpcas_probe_server_config() (docker/wp-cli/lib/probe.php) couldn't
	// read the config file it expects to find that fact in -- e.g. a stack
	// built before this pin existed, or a config file moved without
	// updating the path it's read from. Either way, that's exactly the kind
	// of "known configuration" gap this ticket exists to make loud rather
	// than let a result record silently carry a null where a number belongs.
	$config_facts = array(
		'pool_max_children'                 => 'the PHP-FPM pool worker ceiling',
		'max_execution_time_seconds'        => "PHP's own max_execution_time",
		'request_terminate_timeout_seconds' => "PHP-FPM's request_terminate_timeout",
		'fastcgi_read_timeout_seconds'      => "nginx's fastcgi_read_timeout",
	);
	foreach ( $config_facts as $key => $label ) {
		if ( null === $facts[ $key ] ) {
			$failures[] = sprintf( 'could not read %s back from the running stack (got null)', $label );
		}
	}

	// Issue #31 / ADR-0002: the shared webroot volume is populated once,
	// on first start, from whatever the image contained at that path -- a
	// later rebuild after bumping docker/composer.lock does not reach an
	// already-existing volume. Left undetected, that stale volume would
	// produce a result record that looks entirely plausible while
	// describing WordPress or Action Scheduler software nobody intended
	// to measure. Comparing the live version back against a reference
	// copy of composer.lock kept outside that volume (see
	// wpcas_probe_lockfile_versions()) is what makes the staleness loud
	// instead of silently plausible.
	if ( $facts['wp_version'] !== $facts['wp_version_lockfile'] ) {
		$failures[] = sprintf(
			"WordPress version mismatch: live site reports '%s', composer.lock resolved '%s' -- remove the webroot volume and restart the stack so the rebuilt image repopulates it (see ADR-0002).",
			$facts['wp_version'],
			$facts['wp_version_lockfile']
		);
	}

	if ( $facts['action_scheduler_version'] !== $facts['action_scheduler_version_lockfile'] ) {
		$failures[] = sprintf(
			"Action Scheduler version mismatch: live site reports '%s', composer.lock resolved '%s' -- remove the webroot volume and restart the stack so the rebuilt image repopulates it (see ADR-0002).",
			$facts['action_scheduler_version'],
			$facts['action_scheduler_version_lockfile']
		);
	}

	$ok = array() === $failures;

	return array(
		'ok'       => $ok,
		'failures' => $failures,
		'snapshot' => array(
			'ok'                => $ok,
			'pending_count'     => $facts['pending_count'],
			'callback_attached' => $facts['callback_attached'],
			'cron_in_progress'  => $facts['cron_in_progress'],
			'claims_count'      => $facts['claims_count'],
			// Issue #30: the worker pool ceiling and every relevant
			// timeout, read back from the running stack (see
			// wpcas_probe_server_config() in docker/wp-cli/lib/probe.php)
			// so a reader can attribute an occupancy figure to its
			// configuration from this record alone, without inspecting the
			// image.
			'pool_max_children'                 => $facts['pool_max_children'],
			'max_execution_time_seconds'        => $facts['max_execution_time_seconds'],
			'request_terminate_timeout_seconds' => $facts['request_terminate_timeout_seconds'],
			'fastcgi_read_timeout_seconds'      => $facts['fastcgi_read_timeout_seconds'],
			// Issue #31: the live WordPress/Action Scheduler versions and
			// docker/composer.lock's own resolved versions, so a reader can
			// confirm from this record alone that the measured software
			// matched the lockfile at capture time.
			'wp_version'                        => $facts['wp_version'],
			'wp_version_lockfile'               => $facts['wp_version_lockfile'],
			'action_scheduler_version'          => $facts['action_scheduler_version'],
			'action_scheduler_version_lockfile' => $facts['action_scheduler_version_lockfile'],
			'failures'                          => $failures,
		),
	);
}
