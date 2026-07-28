<?php

declare( strict_types=1 );

/**
 * Measurement probe: report has_action( ActionScheduler_QueueRunner::WP_CRON_HOOK )
 * at six lifecycle stages inside a single real web request.
 *
 * This is NOT a guard. It ships in docker/mu-plugins-available/ purely for
 * source control and reproducibility, but it is not one of the four
 * numbered guard sections bin/guard knows how to arm/disarm -- it is
 * copied into docker/mu-plugins/ directly by bin/measure-unhook-timing,
 * which also removes it again when the measurement is done. It blocks
 * nothing and has zero effect on any request that does not carry the
 * `wpcas_probe=1` query argument.
 *
 * The six stages match issue #8's acceptance criteria exactly:
 *
 *   1. plugins_loaded:10           -- before Action Scheduler's own init
 *                                     chain has scheduled anything on 'init'.
 *   2. init:1                      -- "early init"; registered directly in
 *                                     this file (i.e. before the
 *                                     'plugins_loaded' hook even fires), so
 *                                     it lands ahead of Action Scheduler's
 *                                     own 'init' priority-1 callbacks
 *                                     (those are registered later, from
 *                                     inside the 'plugins_loaded' hook
 *                                     itself) in the same priority bucket.
 *   3. action_scheduler_init:10    -- Action Scheduler's own "I am ready"
 *                                     hook, fired once its init chain
 *                                     (still within 'init' priority 1) has
 *                                     finished attaching the queue runner.
 *   4. init:99                     -- immediately before the unhook guard's
 *                                     'init' priority (100, see
 *                                     40-unhook-queue-runner.php).
 *   5. init:101                    -- immediately after that same priority.
 *   6. wp_loaded:10                -- after 'init' has fully fired.
 *
 * Output: a JSON object on the response body, emitted from a separate
 * 'wp_loaded' priority-20 callback that runs after the stage-6 read above
 * (and exits there), so the measurement script can capture the full set
 * with a single curl call per guard state instead of scraping a log file.
 * The read and the emit/exit are deliberately two different callbacks at
 * two different priorities -- folding the emit into the same callback as
 * the stage-6 read would make the 'wp_loaded:10' label a lie about its own
 * priority.
 */

if ( ! isset( $_GET['wpcas_probe'] ) ) {
	return;
}

$GLOBALS['wpcas_probe_results'] = array();

$wpcas_probe_record = static function ( string $stage ) {
	if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
		$GLOBALS['wpcas_probe_results'][ $stage ] = 'ActionScheduler_QueueRunner not loaded';
		return;
	}

	$hook     = ActionScheduler_QueueRunner::WP_CRON_HOOK;
	$callback = array( ActionScheduler_QueueRunner::instance(), 'run' );
	$priority = has_action( $hook, $callback );

	$GLOBALS['wpcas_probe_results'][ $stage ] = false === $priority
		? 'false'
		: sprintf( 'attached, priority %d', $priority );
};

add_action(
	'plugins_loaded',
	static function () use ( $wpcas_probe_record ) {
		$wpcas_probe_record( 'plugins_loaded:10' );
	},
	10
);

add_action(
	'init',
	static function () use ( $wpcas_probe_record ) {
		$wpcas_probe_record( 'init:1' );
	},
	1
);

add_action(
	'action_scheduler_init',
	static function () use ( $wpcas_probe_record ) {
		$wpcas_probe_record( 'action_scheduler_init:10' );
	},
	10
);

add_action(
	'init',
	static function () use ( $wpcas_probe_record ) {
		$wpcas_probe_record( 'init:99' );
	},
	99
);

add_action(
	'init',
	static function () use ( $wpcas_probe_record ) {
		$wpcas_probe_record( 'init:101' );
	},
	101
);

add_action(
	'wp_loaded',
	static function () use ( $wpcas_probe_record ) {
		$wpcas_probe_record( 'wp_loaded:10' );
	},
	10
);

add_action(
	'wp_loaded',
	static function () {
		$GLOBALS['wpcas_probe_results']['action_scheduler_version'] = class_exists( 'ActionScheduler_Versions' )
			? (string) ActionScheduler_Versions::instance()->latest_version()
			: 'unknown';

		header( 'Content-Type: application/json' );
		echo wp_json_encode( $GLOBALS['wpcas_probe_results'], JSON_PRETTY_PRINT );
		exit;
	},
	20
);
