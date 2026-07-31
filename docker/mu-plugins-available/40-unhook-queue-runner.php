<?php

declare( strict_types=1 );

/**
 * Guard: unhook the queue runner outside WP-CLI.
 *
 * Action Scheduler's ActionScheduler_QueueRunner::init() attaches its own
 * run() method to the 'action_scheduler_run_queue' hook -- the same hook
 * WP-Cron fires on its schedule, the async loopback dispatches to, and a
 * direct call from outside WordPress can trigger too. That attachment
 * happens from Action Scheduler's own 'init' priority-1 callback. Removing
 * the callback before anything can call it turns WP-Cron, the async
 * loopback, and a direct call to that hook all into no-ops: there is
 * simply no callback left to run.
 *
 * remove_action() only succeeds if it runs after the matching
 * add_action() has already executed; running earlier, or targeting a
 * different priority than the one actually used, removes nothing and
 * fails silently -- no warning, no error, the callback stays attached.
 * This guard therefore runs late on 'init', well after Action Scheduler's
 * own priority-1 registration.
 *
 * NOT-VERIFIED here: the priority chosen below (100) is a deliberate
 * margin, not a value this repo has confirmed against a live request on
 * this stack's Action Scheduler version. Confirming that 100 is late
 * enough -- and that nothing else on 'init' re-attaches the callback
 * afterwards -- is left to the ticket that exercises this guard against a
 * live queue run.
 *
 * WP-CLI is exempt: Action Scheduler's own CLI commands
 * (`wp action-scheduler run`, `wp cron event run`) call the runner
 * directly and must keep working.
 */

add_action(
	'init',
	static function () {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return;
		}

		remove_action(
			ActionScheduler_QueueRunner::WP_CRON_HOOK,
			array( ActionScheduler_QueueRunner::instance(), 'run' )
		);
	},
	100
);
