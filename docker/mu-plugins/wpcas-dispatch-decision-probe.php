<?php

declare( strict_types=1 );

/**
 * Measurement-only instrumentation for issue #7's admin-page-load vector
 * (added on review follow-up). This is deliberately NOT one of the four
 * guard files in docker/mu-plugins-available/ -- those are production-
 * shaped, with no conditional toggles or instrumentation of their own
 * (issue #3's own acceptance criterion) -- and it is loaded unconditionally
 * from docker/mu-plugins/, the same way docker/mu-plugins/wpcas-poc-probe.php
 * already is (see that file's own docblock: there is no "arm"/"disarm"
 * step to forget for probe infrastructure that measurements themselves
 * depend on).
 *
 * What this exists to prove, and why "0 drained" alone wasn't enough: the
 * section-2 guard (docker/mu-plugins-available/20-suppress-async-dispatch.php)
 * forces the 'action_scheduler_allow_async_request_runner' filter to
 * false, but a record showing "0 drained" after an admin page load is
 * also exactly what a stale lock, or any other reason the dispatch check
 * never ran at all, would produce. This file adds this repo's own
 * observer on that same filter hook -- registered late (priority 20, after
 * the guard's own default-priority-10 '__return_false', when armed) so it
 * sees whatever value survives every other filter -- and does nothing but
 * record that value and pass it straight through unchanged. It cannot
 * change Action Scheduler's own decision; it only reports it.
 *
 * ActionScheduler_AsyncRequest_QueueRunner::allow() (see that class in the
 * vendored plugin) calls
 * apply_filters( 'action_scheduler_allow_async_request_runner', $allow )
 * exactly once per request, but only after
 * ActionScheduler_QueueRunner::maybe_dispatch_async_request() (hooked on
 * 'shutdown') has already passed its own is_admin() and throttle-lock
 * checks -- so this filter firing at all, on a given request, is itself
 * the positive evidence that Action Scheduler's dispatch decision point
 * was actually reached on that request, not skipped.
 *
 * The result is written to a single dedicated option -- not the probe's
 * own per-execution log (docker/mu-plugins/wpcas-poc-probe.php), which is
 * a different kind of fact (an actual queue execution, not a dispatch
 * decision) -- so a measurement script can clear it before its triggering
 * request and read it back after, the same before/after-scoping
 * discipline the canary log already uses (see docker/wp-cli/lib/canary.php).
 * Read/write glue lives in docker/wp-cli/lib/probe.php
 * (wpcas_probe_reset_dispatch_decision() / wpcas_probe_read_dispatch_decision()).
 */

const WPCAS_DISPATCH_DECISION_OPTION = 'wpcas_dispatch_decision_probe';

add_filter(
	'action_scheduler_allow_async_request_runner',
	static function ( $allow ) {
		global $wpdb;

		// $wpdb->replace(), not update_option(): the reader of this value
		// (docker/wp-cli/lib/probe.php's wpcas_probe_read_dispatch_decision(),
		// called from a long-lived `wp eval-file` process that also calls
		// wpcas_probe_reset_dispatch_decision() earlier in its own
		// lifetime) needs the *current* database row, not whatever
		// WordPress's per-process options cache last thought this key was.
		// update_option()/get_option()/delete_option() all read and write
		// that cache, which is entirely private to *this* PHP process (no
		// persistent/shared object cache is configured on this stack) --
		// so a delete_option() call made by a *different* process (the
		// measurement script) has no way to invalidate this process's own
		// cache, and vice versa. Going straight to $wpdb on both the write
		// side (here) and the read side sidesteps that mismatch entirely --
		// same reasoning as wpcas_probe_clear_async_dispatch_lock()'s own
		// raw-SQL access to Action Scheduler's lock option.
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => WPCAS_DISPATCH_DECISION_OPTION,
				'option_value' => wp_json_encode(
					array(
						'reached'   => true,
						'allowed'   => (bool) $allow,
						'timestamp' => microtime( true ),
					)
				),
				'autoload'     => 'no',
			)
		);

		// Unmodified: this observer must never be able to change the
		// outcome it is reporting on.
		return $allow;
	},
	20
);
