<?php

declare( strict_types=1 );

/**
 * Returns the site to a known state: clears the cron-in-progress
 * transient, empties the claims table, cancels leftover probe actions,
 * clears this probe's own execution log, then re-seeds.
 *
 * Every remediation step is reported explicitly -- "any remediation reset
 * performs must be reported, never silent" -- even when there was nothing
 * to remediate (reporting "0 found" is still a report, not silence).
 *
 * Usage: wp eval-file docker/wp-cli/reset.php [<seed-count>] [due-now]
 *   <seed-count> defaults to WPCAS_PROBE_DEFAULT_SEED_COUNT (50).
 *   due-now      (issue #4) re-seeds with actions due immediately instead
 *                of issue #2's default ~5-minutes-in-the-future schedule
 *                -- see docker/wp-cli/seed.php and wpcas_probe_seed() for
 *                why. Required before measuring a due-now control. A
 *                positional token, not a `--due-now` flag -- see
 *                seed.php's docstring (issue #9) for why.
 *
 * Invoked via `bin/stack reset [<seed-count>] [--due-now]`.
 */

require __DIR__ . '/lib/probe.php';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$due_now = in_array( 'due-now', $args, true );

$count_args = array_values( array_filter( $args, static fn( string $arg ): bool => 'due-now' !== $arg ) );
$seed_count = isset( $count_args[0] ) ? (int) $count_args[0] : WPCAS_PROBE_DEFAULT_SEED_COUNT;

WP_CLI::log( 'Resetting the probe queue...' );

$cron_was_set = wpcas_probe_clear_cron_transient();
WP_CLI::log(
	$cron_was_set
		? 'Cleared the "doing_cron" transient (was set).'
		: 'The "doing_cron" transient was not set; nothing to clear.'
);

$claims_deleted = wpcas_probe_clear_claims();
WP_CLI::log( sprintf( 'Deleted %d claim row(s) from the claims table.', $claims_deleted ) );

$canceled = wpcas_probe_cancel_leftover_actions();
WP_CLI::log(
	$canceled > 0
		? sprintf( 'Canceled %d leftover pending/in-progress probe action(s).', $canceled )
		: 'No leftover pending/in-progress probe actions found.'
);

$log_rows_deleted = wpcas_probe_clear_execution_log();
WP_CLI::log( sprintf( 'Deleted %d probe execution-log row(s).', $log_rows_deleted ) );

// Issue #9: a stale async-dispatch lock from a previous drain trigger would
// make a later trigger request return fast without actually starting a
// drain. Cleared unconditionally, same as every other remediation step
// here, even though only #9's occupancy trigger currently depends on it.
$lock_was_set = wpcas_probe_clear_async_dispatch_lock();
WP_CLI::log(
	$lock_was_set
		? 'Cleared the Action Scheduler async-dispatch lock (was set).'
		: 'The Action Scheduler async-dispatch lock was not set; nothing to clear.'
);

wpcas_probe_seed( $seed_count, $due_now );

WP_CLI::success( 'Reset complete.' );
