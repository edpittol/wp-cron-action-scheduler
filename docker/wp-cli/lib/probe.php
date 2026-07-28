<?php

declare( strict_types=1 );

/**
 * WordPress/WP-CLI-aware glue shared by seed.php, reset.php, and
 * preflight.php (issue #2). Kept apart from lib/preflight-assertions.php
 * (pure, WP-free pass/fail logic) so that file stays trivially
 * unit-testable without a container -- see
 * tests/preflight-assertions.test.php.
 *
 * Depends on the WPCAS_PROBE_HOOK constant defined by the probe mu-plugin
 * (docker/mu-plugins/wpcas-poc-probe.php). mu-plugins load before any
 * WP-CLI command runs -- WP-CLI's own docs note mu-plugins are still
 * loaded even under --skip-plugins -- so by the time this file is
 * required the constant already exists. One hook name, one place, same
 * spirit as this repo's single ACTION_SCHEDULER_VERSION build arg.
 */

if ( ! defined( 'WPCAS_PROBE_HOOK' ) ) {
	WP_CLI::error( 'WPCAS_PROBE_HOOK is undefined -- the probe mu-plugin did not load.' );
}

const WPCAS_PROBE_DEFAULT_SEED_COUNT = 50;

// Seeded actions are scheduled this far in the future, not "now". Action
// Scheduler dispatches an async request on `shutdown` whenever due work
// exists, in both web and CLI contexts -- discovered empirically while
// building this: generating actions with `start = time()` let most of
// them run to `complete` before the seeding process itself had finished
// exiting. A seeded queue that can drain itself out from under the thing
// meant to measure/preflight it is exactly the kind of false result this
// ticket exists to rule out, so seeding schedules deliberately-not-yet-due
// work that stays observably pending until something explicit drains it.
const WPCAS_PROBE_SEED_LEAD_SECONDS = 300;

/**
 * Seeds the queue using Action Scheduler's own `action generate` WP-CLI
 * command -- not a hand-rolled seeder, per the ticket -- so seeding
 * exercises the exact code path a real Action Scheduler caller would.
 *
 * $due_now is issue #9's seam: a worker-occupancy measurement needs a real
 * drain in flight, which needs actions that are actually due, but #2's
 * default lead time (WPCAS_PROBE_SEED_LEAD_SECONDS) deliberately schedules
 * them ~5 minutes out so "confirm 50 pending" stays stable against Action
 * Scheduler's async dispatcher (see that constant's own docstring). This is
 * the minimal due-now capability #9 needs, not the canonical due-now path --
 * issue #4 (a parallel sibling on the same base branch) is independently
 * building that; issue #10 reconciles the two afterward.
 */
function wpcas_probe_seed( int $count, bool $due_now = false ): void {
	if ( $count < 1 ) {
		WP_CLI::error( "count must be a positive integer, got '{$count}'." );
	}

	$start = $due_now ? time() : time() + WPCAS_PROBE_SEED_LEAD_SECONDS;

	WP_CLI::runcommand(
		sprintf(
			'action-scheduler action generate %s %d --count=%d --interval=0',
			WPCAS_PROBE_HOOK,
			$start,
			$count
		)
	);

	WP_CLI::log(
		sprintf(
			'Seeded %d pending "%s" action(s), due %s UTC%s.',
			$count,
			WPCAS_PROBE_HOOK,
			gmdate( 'c', $start ),
			$due_now ? ' (due-now)' : ''
		)
	);
}

/**
 * Pending count for the probe hook.
 *
 * as_get_scheduled_actions() defaults to `per_page => 5` -- found the hard
 * way while building this: a plain `--status=pending` count on the WP-CLI
 * `action list` command silently reported 5 when 7 were actually pending.
 * Every query in this file passes `per_page => -1` explicitly for exactly
 * that reason -- an unnoticed default page size is its own class of false
 * result this ticket's preflight is supposed to guard against.
 */
function wpcas_probe_pending_count(): int {
	return count(
		as_get_scheduled_actions(
			array(
				'hook'     => WPCAS_PROBE_HOOK,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		)
	);
}

function wpcas_probe_callback_attached(): bool {
	return false !== has_action( WPCAS_PROBE_HOOK );
}

/**
 * `doing_cron` is not Action Scheduler's own lock -- it's the WordPress
 * core transient wp-cron.php sets while a cron run is in flight, to stop
 * overlapping spawns (see wp-includes/cron.php). A stale copy left behind
 * by a killed/crashed process would silently block a later cron-triggered
 * drain, which is exactly the kind of unverified precondition preflight
 * exists to catch before trusting any measurement.
 */
function wpcas_probe_cron_in_progress(): bool {
	return false !== get_transient( 'doing_cron' );
}

function wpcas_probe_claims_count(): int {
	global $wpdb;

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_claims" );
}

/**
 * Cancels every currently pending/in-progress probe action left over from
 * a previous run, returning how many were found so callers can report it
 * -- remediation reset performs must never be silent.
 */
function wpcas_probe_cancel_leftover_actions(): int {
	$leftover = as_get_scheduled_actions(
		array(
			'hook'     => WPCAS_PROBE_HOOK,
			'status'   => array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ),
			'per_page' => -1,
		)
	);

	$count = count( $leftover );

	if ( $count > 0 ) {
		WP_CLI::runcommand( sprintf( 'action-scheduler action cancel %s --all', WPCAS_PROBE_HOOK ) );
	}

	return $count;
}

/**
 * Empties Action Scheduler's claims table outright. This is a dev-only,
 * single-tenant stack (see bin/stack's own namespacing comments), so a
 * table-wide delete is in scope for "reset" rather than needing to filter
 * to stale claims only.
 */
function wpcas_probe_clear_claims(): int {
	global $wpdb;

	$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_claims" );

	return (int) $wpdb->rows_affected;
}

/**
 * Clears the cron-in-progress transient (see wpcas_probe_cron_in_progress()
 * for what it actually is), returning whether it was set beforehand.
 */
function wpcas_probe_clear_cron_transient(): bool {
	$was_set = wpcas_probe_cron_in_progress();

	delete_transient( 'doing_cron' );

	return $was_set;
}

/**
 * Clears Action Scheduler's own `async-request-runner` lock (see
 * ActionScheduler_QueueRunner::maybe_dispatch_async_request()), returning
 * whether it was set beforehand.
 *
 * Issue #9 seam: this lock throttles the async dispatch this ticket's
 * occupancy trigger depends on to "at most once every 60 seconds" (see
 * ActionScheduler_Lock::$lock_duration). A lock left over from a previous
 * occupancy run (or any other admin-context request that happened to
 * dispatch one) would make a later trigger request return fast without
 * actually starting a drain -- indistinguishable, from the trigger
 * response alone, from the real "returns almost instantly" behaviour this
 * ticket is trying to measure. Clearing it immediately before triggering
 * rules that specific false result out, the same way reset.php already
 * rules out a stale "doing_cron" transient before preflight.
 */
function wpcas_probe_clear_async_dispatch_lock(): bool {
	$lock_option = 'action_scheduler_lock_async-request-runner';

	$was_set = false !== get_option( $lock_option, false );

	delete_option( $lock_option );

	return $was_set;
}

/**
 * Deletes every per-execution log row the probe callback wrote (see
 * wpcas_probe_record_execution() in the mu-plugin), returning how many
 * were removed.
 */
function wpcas_probe_clear_execution_log(): int {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( WPCAS_PROBE_LOG_PREFIX ) . '%'
		)
	);

	return (int) $wpdb->rows_affected;
}
