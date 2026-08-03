<?php

declare( strict_types=1 );

/**
 * Asserts the conditions that must hold before any measurement taken
 * against the probe queue can be trusted:
 *
 *   - a non-zero pending count
 *   - an attached callback
 *   - no cron-in-progress transient
 *   - an empty claims table
 *   - the live WordPress and Action Scheduler versions match what
 *     docker/composer.lock resolved (issue #31 / ADR-0002)
 *
 * Always emits a machine-readable snapshot of what it found -- pass or
 * fail -- "so later results can carry their own proof of validity". On
 * any failed assertion it then aborts loudly (non-zero exit, explicit
 * failure list on STDERR) rather than continuing.
 *
 * Usage: wp eval-file docker/wp-cli/preflight.php
 *
 * Invoked via `bin/stack preflight`.
 */

require __DIR__ . '/lib/probe.php';
require __DIR__ . '/lib/preflight-assertions.php';

$lockfile_versions = wpcas_probe_lockfile_versions();

$facts = array(
	'pending_count'                     => wpcas_probe_pending_count(),
	'callback_attached'                 => wpcas_probe_callback_attached(),
	'cron_in_progress'                  => wpcas_probe_cron_in_progress(),
	'claims_count'                      => wpcas_probe_claims_count(),
	'wp_version'                        => wpcas_probe_wp_version(),
	'wp_version_lockfile'               => $lockfile_versions['wordpress'],
	'action_scheduler_version'          => wpcas_probe_action_scheduler_version(),
	'action_scheduler_version_lockfile' => $lockfile_versions['action_scheduler'],
);

$result = wpcas_preflight_evaluate( $facts );

// Emitted unconditionally, before any abort, so a failing run still
// produces the machine-readable snapshot it was asked for.
WP_CLI::log( wp_json_encode( $result['snapshot'], JSON_PRETTY_PRINT ) );

if ( ! $result['ok'] ) {
	WP_CLI::error(
		"Preflight FAILED:\n  - " . implode( "\n  - ", $result['failures'] ),
		false
	);
	WP_CLI::halt( 1 );
}

WP_CLI::success( 'Preflight passed.' );
