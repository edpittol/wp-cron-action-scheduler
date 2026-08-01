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

// docker/mu-plugins/wpcas-dispatch-decision-probe.php (issue #7 review
// follow-up) -- same "mu-plugins load before any WP-CLI command runs"
// guarantee as WPCAS_PROBE_HOOK above.
if ( ! defined( 'WPCAS_DISPATCH_DECISION_OPTION' ) ) {
	WP_CLI::error( 'WPCAS_DISPATCH_DECISION_OPTION is undefined -- the dispatch-decision probe mu-plugin did not load.' );
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
 * $due_now (issue #4): the default (false) keeps issue #2's
 * not-yet-due scheduling, which is what makes "confirm 50 pending" a
 * stable precondition. A due-now control (`wp cron event run --due-now`,
 * `wp action-scheduler run`) has nothing to drain against that queue --
 * future-scheduled actions are not due, so a due-now runner processes 0 of
 * them. Draining 50 to 0 needs actions that are actually due, hence this
 * flag: it schedules at `time()` instead of `time() + lead`, deliberately
 * accepting the self-drain risk documented on
 * WPCAS_PROBE_SEED_LEAD_SECONDS above -- callers measuring a due-now
 * control need exactly that risk to verify their own dispatch-control
 * story (see docker/wp-cli/measure.php), so it can't be designed away
 * here the way the default (not-due) path does.
 *
 * Issue #9 independently built the same seam for the same reason: a
 * worker-occupancy measurement also needs a real drain in flight, which
 * also needs actions that are actually due. #4 and #9 were parallel
 * siblings on the same base branch that arrived at an identical
 * `$due_now` parameter; issue #10 reconciled them into this single
 * implementation (see issue #10's `## Decisions`).
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

// --- Issue #30: config facts read back from the running stack ------------

/**
 * Container-absolute paths this stack pins the four config facts at.
 *
 * `WPCAS_FPM_POOL_CONF_PATH` / `WPCAS_PHP_EXECUTION_TIME_INI_PATH`: baked
 * into the php-fpm container's own image by docker/Dockerfile -- see that
 * file's own comments for why they're named/placed the way they are (the
 * `zz-` prefix in particular, so this pool override loads after, and
 * therefore wins over, the base image's own `www.conf`/`docker.conf`).
 *
 * `WPCAS_NGINX_FASTCGI_READ_TIMEOUT_PATH`: nginx's own upstream read
 * timeout lives in docker/nginx/fastcgi-read-timeout.conf, a file nginx
 * itself includes (docker/nginx/default.conf) -- but nginx is a separate
 * Compose service with its own filesystem, unreachable from a `wp eval-file`
 * process running inside the php-fpm container. docker-compose.yml
 * bind-mounts that exact same file, read-only, into this container too
 * (this second, container-internal path), so preflight reads back the
 * identical bytes nginx itself is running with, rather than a second,
 * separately-maintained copy of the number that could silently drift out of
 * agreement with it.
 */
const WPCAS_FPM_POOL_CONF_PATH               = '/usr/local/etc/php-fpm.d/zz-wpcas-pool.conf';
const WPCAS_PHP_EXECUTION_TIME_INI_PATH       = '/usr/local/etc/php/conf.d/execution-time.ini';
const WPCAS_NGINX_FASTCGI_READ_TIMEOUT_PATH   = '/etc/wpcas/nginx-fastcgi-read-timeout.conf';

/**
 * Reads the four config facts issue #30 requires the preflight snapshot to
 * carry, straight from the config files docker/Dockerfile / docker/nginx/
 * actually pin them in -- never a separate, restated literal (see
 * docker/wp-cli/lib/server-config.php's module docblock for why, and for
 * why `max_execution_time` is read this way rather than via `ini_get()`).
 * A file that can't be read (should not happen in a correctly-built,
 * correctly-composed stack) reports `null` for that fact rather than
 * fataling -- wpcas_preflight_evaluate() is where a `null` gets turned into
 * a loud, explicit preflight failure instead of a silently-missing number.
 *
 * @return array{
 *     pool_max_children: int|null,
 *     max_execution_time_seconds: int|null,
 *     request_terminate_timeout_seconds: int|null,
 *     fastcgi_read_timeout_seconds: int|null,
 * }
 */
function wpcas_probe_server_config(): array {
	$pool_conf  = @file_get_contents( WPCAS_FPM_POOL_CONF_PATH );
	$exec_ini   = @file_get_contents( WPCAS_PHP_EXECUTION_TIME_INI_PATH );
	$nginx_conf = @file_get_contents( WPCAS_NGINX_FASTCGI_READ_TIMEOUT_PATH );

	return array(
		'pool_max_children'                 => false !== $pool_conf ? wpcas_server_config_parse_pool_max_children( $pool_conf ) : null,
		'request_terminate_timeout_seconds' => false !== $pool_conf ? wpcas_server_config_parse_request_terminate_timeout( $pool_conf ) : null,
		'max_execution_time_seconds'        => false !== $exec_ini ? wpcas_server_config_parse_max_execution_time( $exec_ini ) : null,
		'fastcgi_read_timeout_seconds'      => false !== $nginx_conf ? wpcas_server_config_parse_fastcgi_read_timeout( $nginx_conf ) : null,
	);
}

/**
 * The full fact set every preflight-shaped check gathers before calling
 * wpcas_preflight_evaluate() -- preflight.php itself, plus every
 * measure-*.php's own internal preflight re-check. Centralised here (issue
 * #30) so the four config facts that ticket adds are wired into every one
 * of those call sites through this single function, rather than needing
 * the same literal facts array hand-copied into six files (the exact
 * "disagreeing copies" trap this ticket's own acceptance criteria warns
 * against for the pool size specifically, generalised here to every call
 * site rather than just every config value).
 *
 * @return array{
 *     pending_count: int,
 *     callback_attached: bool,
 *     cron_in_progress: bool,
 *     claims_count: int,
 *     pool_max_children: int|null,
 *     max_execution_time_seconds: int|null,
 *     request_terminate_timeout_seconds: int|null,
 *     fastcgi_read_timeout_seconds: int|null,
 * }
 */
function wpcas_probe_gather_preflight_facts(): array {
	return array_merge(
		array(
			'pending_count'     => wpcas_probe_pending_count(),
			'callback_attached' => wpcas_probe_callback_attached(),
			'cron_in_progress'  => wpcas_probe_cron_in_progress(),
			'claims_count'      => wpcas_probe_claims_count(),
		),
		wpcas_probe_server_config()
	);
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
 * Clears Action Scheduler's own "async-request-runner" throttle lock
 * (`ActionScheduler_OptionLock`, stored as the autoloaded option
 * `action_scheduler_lock_async-request-runner`, 60 seconds by default --
 * see ActionScheduler_QueueRunner::maybe_dispatch_async_request() /
 * ActionScheduler_OptionLock::set() in the action-scheduler plugin).
 *
 * Discovered while building issue #7's admin-page-load vector: that
 * dispatch check (`is_admin() && ! is_locked(...) && set(...)`) only
 * *attempts* a dispatch at most once every 60 seconds, regardless of how
 * many admin page loads happen in between. A lock left over from a
 * previous run (this ticket's own repeated preflight/reset/measure
 * cycles, run from the same container within that window) would make an
 * otherwise-eligible "unarmed" run silently skip its own dispatch and
 * report a false "nothing drained" -- indistinguishable, without this
 * check, from the guard actually doing its job. Cleared unconditionally
 * as part of getting a clean starting state for this vector, the same
 * spirit as wpcas_probe_clear_cron_transient() for WP-Cron's own lock.
 *
 * Issue #9 needed the exact same clear independently, for the same
 * underlying reason: a lock left over from a previous occupancy run (or
 * any other admin-context request that happened to dispatch one) would
 * make a later drain-trigger request return fast without actually
 * starting a drain -- indistinguishable, from the trigger response alone,
 * from the real "returns almost instantly" behaviour that ticket measures.
 * Issue #10 reconciled #7's and #9's independently-built versions of this
 * function into this single implementation (see issue #10's `## Decisions`).
 *
 * Returns whether a lock was actually present beforehand, so callers can
 * report it rather than silently no-op.
 */
function wpcas_probe_clear_async_dispatch_lock(): bool {
	global $wpdb;

	$lock_key = 'action_scheduler_lock_async-request-runner';

	$existing = $wpdb->get_var(
		$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock_key )
	);

	if ( null === $existing || '' === $existing ) {
		return false;
	}

	$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_key ) );

	return true;
}

/**
 * Polls wpcas_probe_pending_count() until either it reaches zero, or it
 * has made *some* progress from $pending_before and then stabilises
 * across $stable_reads_required consecutive checks -- for up to
 * $max_wait_seconds of wall-clock time in total.
 *
 * Exists because Action Scheduler's async-loopback dispatch (see
 * docker/mu-plugins-available/20-suppress-async-dispatch.php, and
 * ActionScheduler_AsyncRequest_QueueRunner::maybe_dispatch() /
 * WP_Async_Request::dispatch() in the plugin itself) is deliberately
 * fire-and-forget from the *dispatching* request's own point of view --
 * WP_Async_Request::dispatch() sends its loopback POST with a sub-second
 * timeout by design, precisely so the page load that triggers it isn't
 * held up waiting for a full queue drain. The triggering HTTP request
 * this measurement makes (see docker/wp-cli/measure-admin-page-load.php)
 * therefore returns long before the loopback's own processing -- a
 * second, genuinely separate request against this same server -- has had
 * a chance to finish. Reading pending_after immediately after the
 * triggering request returns would misreport an in-flight (or
 * not-yet-started) drain as "0 drained", which is exactly the kind of
 * false result this ticket's evidence pipeline exists to catch.
 *
 * Review follow-up (issue #7): an earlier version of this function
 * treated "N consecutive equal reads" as settled regardless of whether
 * any of those reads had ever actually dropped below $pending_before.
 * That is correct once at least one decrease has been observed (proof
 * the loopback genuinely started; further stability then just means it
 * finished, or paused, and is fine to report as-is) -- but it is exactly
 * the wrong heuristic for "the count never moved at all", because a
 * dispatch that is real but merely slow to start (new PHP worker,
 * bootstrap, network hop to its own server) can look identical, for a
 * few quick reads, to no dispatch having happened at all. A run observed
 * during development landed its first execution ~2.4s after the
 * triggering request returned -- close enough to the old ~2s
 * (3 x 1s-interval) settle window that a slightly slower run could have
 * been misread as "0 drained" (a false negative that would look
 * indistinguishable from the guard actually working). This function
 * therefore only allows the fast "stable -> settled" exit once progress
 * has actually been observed; with no progress at all, it holds out for
 * the *entire* $max_wait_seconds before concluding "0 drained", trading
 * a slower measurement for one that cannot mistake "hasn't started yet"
 * for "isn't going to happen". (Positive, direct evidence that the
 * dispatch decision itself was reached and vetoed -- rather than
 * inferring it from timing at all -- is captured separately; see
 * wpcas_probe_read_dispatch_decision() below.)
 *
 * @return array{pending: int, settled: bool, timed_out: bool, progress_observed: bool, waited_seconds: float}
 */
function wpcas_probe_poll_until_settled( int $pending_before, int $max_wait_seconds, int $poll_interval_seconds = 1, int $stable_reads_required = 3 ): array {
	$start    = microtime( true );
	$deadline = $start + $max_wait_seconds;

	$last   = wpcas_probe_pending_count();
	$stable = 1;

	while ( true ) {
		$progress_observed = $last < $pending_before;

		if ( 0 === $last || ( $progress_observed && $stable >= $stable_reads_required ) ) {
			return array(
				'pending'           => $last,
				'settled'           => true,
				'timed_out'         => false,
				'progress_observed' => $progress_observed,
				'waited_seconds'    => microtime( true ) - $start,
			);
		}

		if ( microtime( true ) >= $deadline ) {
			return array(
				'pending'           => $last,
				// No progress ever, after the full wait: settle on that
				// reading as-is (it's the best evidence available), but
				// `timed_out` below is what flags it as "waited the full
				// window without ever seeing a decrease" rather than a
				// confidently-observed stabilisation.
				'settled'           => $progress_observed,
				'timed_out'         => true,
				'progress_observed' => $progress_observed,
				'waited_seconds'    => microtime( true ) - $start,
			);
		}

		sleep( $poll_interval_seconds );

		$current = wpcas_probe_pending_count();
		$stable  = ( $current === $last ) ? $stable + 1 : 1;
		$last    = $current;
	}
}

/**
 * Clears the dispatch-decision probe's own option (see
 * docker/mu-plugins/wpcas-dispatch-decision-probe.php) before a
 * measurement's triggering request, so a stale value from a previous run
 * can't be mistaken for this run's own evidence.
 *
 * Raw $wpdb, not delete_option() -- discovered the hard way while
 * building this: delete_option(), called here in this long-lived
 * `wp eval-file` process, marks the key as confirmed-absent in *this*
 * process's own options cache. The dispatch-decision probe's later write
 * (docker/mu-plugins/wpcas-dispatch-decision-probe.php) happens in a
 * completely different PHP process (the HTTP server's worker handling the
 * triggering admin request), which has no way to invalidate this
 * process's cache -- so a subsequent get_option() call in *this* process
 * would trust its own stale "not found" entry and never re-query the
 * database, even though the other process's row genuinely exists. This
 * stack has no persistent/shared object cache, so raw $wpdb access is the
 * only way both sides reliably see the same, current row -- same
 * reasoning as wpcas_probe_clear_async_dispatch_lock()'s existing raw-SQL
 * access to Action Scheduler's own lock option.
 */
function wpcas_probe_reset_dispatch_decision(): void {
	global $wpdb;

	$wpdb->delete( $wpdb->options, array( 'option_name' => WPCAS_DISPATCH_DECISION_OPTION ) );
}

/**
 * Reads back whatever docker/mu-plugins/wpcas-dispatch-decision-probe.php
 * recorded for the most recent request that reached Action Scheduler's
 * async-dispatch decision point. `reached: false` (with `allowed: null`)
 * means the option was never (re)written since the last
 * wpcas_probe_reset_dispatch_decision() call -- i.e. the decision point
 * was never reached at all on this run, not merely that it was reached
 * and denied.
 *
 * Raw $wpdb, not get_option() -- see wpcas_probe_reset_dispatch_decision()'s
 * own docblock for why: this process's options cache cannot be trusted to
 * reflect a row written by a different process just moments earlier.
 *
 * @return array{reached: bool, allowed: bool|null}
 */
function wpcas_probe_read_dispatch_decision(): array {
	global $wpdb;

	$raw = $wpdb->get_var(
		$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WPCAS_DISPATCH_DECISION_OPTION )
	);

	if ( null === $raw || '' === $raw ) {
		return array(
			'reached' => false,
			'allowed' => null,
		);
	}

	$decoded = json_decode( (string) $raw, true );

	if ( ! is_array( $decoded ) || ! array_key_exists( 'reached', $decoded ) || ! array_key_exists( 'allowed', $decoded ) ) {
		return array(
			'reached' => false,
			'allowed' => null,
		);
	}

	return array(
		'reached' => (bool) $decoded['reached'],
		'allowed' => (bool) $decoded['allowed'],
	);
}

/**
 * The four guard-section filenames bin/guard itself toggles (see that
 * script's own section_file() mapping) -- duplicated here deliberately
 * rather than shelled out to bin/guard, so this stays a pure filesystem
 * read usable from inside a `wp eval-file` process.
 */
const WPCAS_GUARD_SECTION_FILES = array(
	1 => '10-block-http-cron.php',
	2 => '20-suppress-async-dispatch.php',
	3 => '30-log-non-cli-canary.php',
	4 => '40-unhook-queue-runner.php',
);

/**
 * Positive evidence of which guard sections are armed for *this* process
 * -- added on review follow-up for issue #7, whose manual-run vector
 * needs to tell "the canary was armed but didn't fire" (its actual,
 * documented finding) apart from "the canary was never armed in the
 * first place" (which would make that finding meaningless).
 *
 * `armed_guard_files`: reads presence of each of the four known guard
 * files directly under wp-content/mu-plugins/ -- the exact same
 * file-presence toggle bin/guard arm/status itself uses (WordPress loads
 * every file in that directory unconditionally on every request, web or
 * CLI -- see the module docblock at the top of this file for why a check
 * made from inside a `wp eval-file` script reflects the same file set the
 * HTTP server serving this same container has also loaded).
 *
 * `canary_action_hook_registered`: a second, independent signal specific
 * to the section-3 canary -- confirms its *specific* callback actually
 * registered in this process's own hook table, not merely that a
 * same-named file exists on disk (e.g. ruling out a fatal parse error in
 * that specific file silently preventing registration despite the file
 * being present).
 *
 * Found the hard way while adding this: a plain
 * `has_action( 'action_scheduler_before_process_queue' )` is USELESS here
 * -- Action Scheduler's own code unconditionally hooks that same action
 * itself (ActionScheduler_RecurringActionScheduler::schedule_recurring_scheduler_hook,
 * and, when applicable, ActionScheduler_wpCommentLogger::disable_comment_counting;
 * see classes/ActionScheduler_RecurringActionScheduler.php /
 * classes/data-stores/ActionScheduler_wpCommentLogger.php in the vendored
 * plugin), so has_action() on this hook returns a registered callback
 * whether or not the canary guard is armed at all -- a false positive
 * that would have made this "positive evidence" no evidence at all.
 * wpcas_probe_canary_hook_registered() below instead walks the hook's own
 * registered callbacks (via $wp_filter) and, for each one that is a
 * Closure (the guard file's callback is an anonymous function -- see
 * docker/mu-plugins-available/30-log-non-cli-canary.php), uses
 * ReflectionFunction::getFileName() to check whether *that specific*
 * closure was defined in the guard file's own path -- Action Scheduler's
 * own callbacks are object methods, not closures, so they can never match.
 *
 * @return array{armed_guard_files: array<string, bool>, canary_action_hook_registered: bool}
 */
function wpcas_probe_canary_hook_registered(): bool {
	global $wp_filter;

	$hook_name = 'action_scheduler_before_process_queue';

	if ( ! isset( $wp_filter[ $hook_name ] ) || ! ( $wp_filter[ $hook_name ] instanceof WP_Hook ) ) {
		return false;
	}

	$expected_file = realpath( WPMU_PLUGIN_DIR . '/' . WPCAS_GUARD_SECTION_FILES[3] );

	if ( false === $expected_file ) {
		// The guard file isn't even present -- can't possibly be registered.
		return false;
	}

	foreach ( $wp_filter[ $hook_name ]->callbacks as $registered_at_priority ) {
		foreach ( $registered_at_priority as $registration ) {
			$callable = $registration['function'] ?? null;

			if ( ! ( $callable instanceof Closure ) ) {
				continue; // Action Scheduler's own callbacks are object methods, never closures.
			}

			try {
				$reflection = new ReflectionFunction( $callable );
			} catch ( ReflectionException $e ) {
				continue;
			}

			if ( realpath( (string) $reflection->getFileName() ) === $expected_file ) {
				return true;
			}
		}
	}

	return false;
}

function wpcas_probe_guard_state(): array {
	$armed = array();

	foreach ( WPCAS_GUARD_SECTION_FILES as $file ) {
		$armed[ $file ] = file_exists( WPMU_PLUGIN_DIR . '/' . $file );
	}

	return array(
		'armed_guard_files'             => $armed,
		'canary_action_hook_registered' => wpcas_probe_canary_hook_registered(),
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
