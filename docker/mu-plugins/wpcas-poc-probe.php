<?php

declare( strict_types=1 );

/**
 * Minimal, dependency-free callback used to prove the Action Scheduler +
 * SQLite integration end to end (see the "one scheduled action runs to
 * completion" acceptance criterion for issue #1), and to power the
 * trustworthy seeded queue built in issue #2.
 *
 * A real callback has to be registered *before* an action targeting this
 * hook is claimed and run -- Action Scheduler logs
 * "will not be executed as no callbacks are registered" and marks the
 * action failed otherwise. A mu-plugin is the simplest way to guarantee
 * that registration happens on every request/CLI invocation without
 * depending on plugin activation order -- there is no "arm"/"disarm" step
 * to forget, which is the point: an action whose hook has no attached
 * callback completes as an instant no-op (pending count falls to zero,
 * nothing actually ran), and that is the single most convincing false
 * result available in this problem space. Loading unconditionally from a
 * mu-plugin, in every context (web and CLI alike), makes that impossible
 * to produce here by accident.
 *
 * Each execution:
 *   - costs ~200ms (usleep), so a seeded queue's drain time means
 *     something instead of measuring an instant no-op;
 *   - records which SAPI ran it, its process id, and when it ran, as its
 *     own wp_options row (see wpcas_probe_record_execution()) -- enough
 *     to later confirm the callback really executed in both web and CLI
 *     contexts, not merely that it was registered in both.
 *
 * Usage:
 *   wp eval 'as_enqueue_async_action( "wpcas_poc_probe" );'
 *   wp action-scheduler run
 *   wp eval 'echo get_option( "wpcas_poc_probe_last_run" );'
 *
 * Seeding, resetting, and preflight-checking a batch of these actions is
 * scripted in docker/wp-cli/ (see bin/stack seed|reset|preflight).
 */

// Single source of truth for the hook name -- docker/wp-cli/lib/probe.php
// depends on this constant already being defined by the time it runs
// (mu-plugins load before any WP-CLI command executes).
const WPCAS_PROBE_HOOK = 'wpcas_poc_probe';

// ~200ms: a real, measurable cost per execution, not an instant no-op.
const WPCAS_PROBE_EXECUTION_COST_USEC = 200000;

// Prefix for the per-execution log rows -- see wpcas_probe_record_execution().
const WPCAS_PROBE_LOG_PREFIX = 'wpcas_probe_exec_';

/**
 * Persists one execution record as its own autoload=no wp_options row.
 *
 * A single growing "log" option would need a read-modify-write on every
 * execution, which races when Action Scheduler runs actions concurrently
 * across web/CLI/async-request processes -- two executions could each
 * read the same old value and overwrite each other's entry. add_option()
 * with a unique name per execution is a single INSERT per call instead,
 * so concurrent executions can't clobber one another.
 *
 * docker/wp-cli/lib/probe.php's wpcas_probe_clear_execution_log() deletes
 * every row with this prefix as part of reset.
 */
function wpcas_probe_record_execution( float $started_at ): void {
	$record = array(
		'sapi'      => PHP_SAPI,
		'pid'       => getmypid(),
		'timestamp' => $started_at,
	);

	$unique_suffix = getmypid() . '_' . uniqid( '', true ) . '_' . random_int( 0, PHP_INT_MAX );

	add_option(
		WPCAS_PROBE_LOG_PREFIX . $unique_suffix,
		wp_json_encode( $record ),
		false,
		'no'
	);

	// Kept for issue #1's manual verification recipe (see Usage above).
	update_option( 'wpcas_poc_probe_last_run', time() );
}

add_action(
	WPCAS_PROBE_HOOK,
	static function () {
		$started_at = microtime( true );
		usleep( WPCAS_PROBE_EXECUTION_COST_USEC );
		wpcas_probe_record_execution( $started_at );
	}
);
