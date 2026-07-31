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
 * $due_now: the default (false) keeps issue #2's not-yet-due scheduling,
 * which is what makes "confirm 50 pending" a stable precondition against
 * Action Scheduler's async dispatcher (see that constant's own docstring).
 * Two callers need the opposite, and share this one seam:
 *
 * - issue #4's due-now CLI controls (`wp cron event run --due-now`,
 *   `wp action-scheduler run`) have nothing to drain against a not-yet-due
 *   queue -- future-scheduled actions are not due, so a due-now runner
 *   processes 0 of them, and draining 50 to 0 needs actions actually due;
 * - issue #9's worker-occupancy measurement needs a real drain in flight,
 *   which likewise needs due actions.
 *
 * So the flag schedules at `time()` instead of `time() + lead`,
 * deliberately accepting the self-drain risk documented on
 * WPCAS_PROBE_SEED_LEAD_SECONDS above -- callers measuring a due-now
 * control need exactly that risk to verify their own dispatch-control
 * story (see docker/wp-cli/measure.php), so it can't be designed away
 * here the way the default (not-due) path does.
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

/**
 * IDs of every probe action currently pending, for the fact-gathering
 * step of a measured run (issue #4) -- captured *before* a control runs,
 * so the record's later log-message lookup (see
 * wpcas_probe_log_messages_for_actions()) can be scoped to exactly the
 * batch a measurement is about, not any leftover action IDs from a
 * previous run's cancellations.
 *
 * `per_page => -1`: see wpcas_probe_pending_count() for why every query
 * in this file passes it explicitly.
 */
function wpcas_probe_pending_action_ids(): array {
	return as_get_scheduled_actions(
		array(
			'hook'     => WPCAS_PROBE_HOOK,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		),
		'ids'
	);
}

/**
 * Action Scheduler's own log messages (its `{$wpdb->prefix}actionscheduler_logs`
 * table -- see ActionScheduler_DBLogger::log() in the action-scheduler
 * plugin) for a specific set of action IDs, in the order they were
 * written.
 *
 * Scoped to explicit action IDs rather than "everything in the table"
 * because that table is never cleared by reset -- unlike this probe's own
 * execution log (wpcas_probe_clear_execution_log()) or the claims table
 * (wpcas_probe_clear_claims()), Action Scheduler's log table is
 * intentionally left alone by reset (its own history is not this probe's
 * to erase), so an unscoped read after more than one measured run would
 * pick up messages from every prior run too.
 *
 * @param int[] $action_ids
 * @return string[]
 */
function wpcas_probe_log_messages_for_actions( array $action_ids ): array {
	global $wpdb;

	if ( array() === $action_ids ) {
		return array();
	}

	$placeholders = implode( ', ', array_fill( 0, count( $action_ids ), '%d' ) );

	return $wpdb->get_col(
		$wpdb->prepare(
			"SELECT message FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$placeholders}) ORDER BY log_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$action_ids
		)
	);
}

/**
 * Every per-execution record the probe callback wrote (see
 * wpcas_probe_record_execution() in the mu-plugin) -- "the probe's own
 * records" the result record (issue #4) is required to carry, independent
 * of anything Action Scheduler itself reports. Decoded from JSON back into
 * plain arrays; a row that fails to decode (should not happen -- these are
 * always written by wp_json_encode() in the mu-plugin) is skipped rather
 * than fatally breaking a measurement over one corrupt row.
 *
 * @return array<int, array{sapi: string, pid: int, timestamp: float}>
 */
function wpcas_probe_execution_log_entries(): array {
	global $wpdb;

	$raw = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC",
			$wpdb->esc_like( WPCAS_PROBE_LOG_PREFIX ) . '%'
		)
	);

	$records = array();
	foreach ( $raw as $json ) {
		$decoded = json_decode( $json, true );
		if ( is_array( $decoded ) ) {
			$records[] = $decoded;
		}
	}

	return $records;
}
