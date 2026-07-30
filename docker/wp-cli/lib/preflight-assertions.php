<?php

declare( strict_types=1 );

/**
 * Pure evaluation logic for the preflight gate (issue #2).
 *
 * Deliberately free of any WordPress/$wpdb/WP-CLI dependency, so it can
 * be exercised with plain `php`, no container or WP bootstrap required --
 * see tests/preflight-assertions.test.php. All the WP-aware fact-gathering
 * (querying Action Scheduler, $wpdb, transients) lives in
 * docker/wp-cli/lib/probe.php instead, which calls into this file.
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
			'failures'          => $failures,
		),
	);
}
