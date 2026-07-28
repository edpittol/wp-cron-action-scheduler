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
 */
function wpcas_probe_seed( int $count ): void {
	if ( $count < 1 ) {
		WP_CLI::error( "count must be a positive integer, got '{$count}'." );
	}

	$start = time() + WPCAS_PROBE_SEED_LEAD_SECONDS;

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
			'Seeded %d pending "%s" action(s), due %s UTC.',
			$count,
			WPCAS_PROBE_HOOK,
			gmdate( 'c', $start )
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
