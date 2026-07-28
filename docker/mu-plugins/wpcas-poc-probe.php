<?php
/**
 * Minimal, dependency-free callback used to prove the Action Scheduler +
 * SQLite integration end to end (see the "one scheduled action runs to
 * completion" acceptance criterion for issue #1).
 *
 * A real callback has to be registered *before* an action targeting this
 * hook is claimed and run -- Action Scheduler logs
 * "will not be executed as no callbacks are registered" and marks the
 * action failed otherwise. A mu-plugin is the simplest way to guarantee
 * that registration happens on every request/CLI invocation without
 * depending on plugin activation order.
 *
 * Usage:
 *   wp eval 'as_enqueue_async_action( "wpcas_poc_probe" );'
 *   wp action-scheduler run
 *   wp eval 'echo get_option( "wpcas_poc_probe_last_run" );'
 */
add_action(
	'wpcas_poc_probe',
	static function () {
		update_option( 'wpcas_poc_probe_last_run', time() );
	}
);
