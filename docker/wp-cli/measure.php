<?php

declare( strict_types=1 );

/**
 * Runs one of the two CLI controls (issue #4) against the seeded probe
 * queue and writes a single timestamped result record -- the unit of
 * evidence every later ticket in this series produces (see
 * docker/wp-cli/lib/result-record.php for the record's shape and why it's
 * built the way it is).
 *
 * Usage: wp eval-file docker/wp-cli/measure.php <control>
 *   <control> is one of:
 *     wp-cron           `wp cron event run --due-now` -- drives the queue
 *                        through the WP-Cron hook. Action Scheduler logs
 *                        this execution context as "WP Cron".
 *     action-scheduler  `wp action-scheduler run` -- Action Scheduler's
 *                        own CLI runner, bypasses the WP-Cron hook
 *                        entirely. Logged as "WP CLI".
 *
 * Invoked via `bin/stack measure <control>`, which also captures this
 * script's stdout (the JSON record, and nothing else -- every other
 * message here goes to STDERR on purpose) to a file under results/.
 *
 * Preconditions this script enforces itself, not just documents: it
 * preflights before doing anything else and refuses to measure (same
 * abort contract as preflight.php: non-zero exit, no record written) if
 * preflight fails. Callers are still expected to have run
 * `bin/stack reset <count> --due-now` first -- preflight does not check
 * *when* actions are due, only that some are pending, a callback is
 * attached, and the site is otherwise in a clean state (see
 * docker/wp-cli/lib/preflight-assertions.php) -- a due-now control run
 * against issue #2's default (not-yet-due) seed will still pass preflight
 * and then measure a 0-action drain, which is exactly the negative result
 * this script is required to report faithfully rather than paper over.
 */

require __DIR__ . '/lib/server-config.php';
require __DIR__ . '/lib/probe.php';
require __DIR__ . '/lib/preflight-assertions.php';
require __DIR__ . '/lib/result-record.php';

/**
 * The two controls this ticket exists to measure. `argv` is the exact
 * WP-CLI command named in the ticket for that control; `context` is the
 * literal string Action Scheduler's own logger is expected to record for
 * it (see wpcas_result_summarize_execution_contexts()) -- kept here only
 * as documentation/labels for the CLI usage message, never used to
 * *decide* the outcome (see the module docblock on result-record.php).
 */
const WPCAS_MEASURE_CONTROLS = array(
	'wp-cron'          => array(
		'argv'    => 'cron event run --due-now',
		'context' => 'WP Cron',
	),
	'action-scheduler' => array(
		'argv'    => 'action-scheduler run',
		'context' => 'WP CLI',
	),
);

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$control = $args[0] ?? '';

if ( ! isset( WPCAS_MEASURE_CONTROLS[ $control ] ) ) {
	WP_CLI::error(
		sprintf(
			"Unknown control '%s'. Expected one of: %s",
			$control,
			implode( ', ', array_keys( WPCAS_MEASURE_CONTROLS ) )
		)
	);
}

$command_argv = WPCAS_MEASURE_CONTROLS[ $control ]['argv'];

// --- Preflight (same facts/evaluation as preflight.php) -----------------

$preflight_facts = wpcas_probe_gather_preflight_facts();
$preflight       = wpcas_preflight_evaluate( $preflight_facts );

fwrite( STDERR, wp_json_encode( $preflight['snapshot'], JSON_PRETTY_PRINT ) . "\n" );

if ( ! $preflight['ok'] ) {
	fwrite( STDERR, "Preflight FAILED, refusing to measure:\n" );
	foreach ( $preflight['failures'] as $failure ) {
		fwrite( STDERR, "  - {$failure}\n" );
	}
	WP_CLI::halt( 1 );
}

// --- Capture pre-run state, scoped to exactly this batch -----------------

$pending_before = $preflight_facts['pending_count'];
$action_ids     = wpcas_probe_pending_action_ids();

fwrite(
	STDERR,
	sprintf( "Preflight passed. Running control '%s' (%s) against %d pending action(s)...\n", $control, $command_argv, $pending_before )
);

// --- Run the control ------------------------------------------------------
//
// launch=true: spawns a genuinely separate `wp` process (see
// WP_CLI::runcommand() in wp-cli/wp-cli), the same as running
// `wp cron event run --due-now` or `wp action-scheduler run` from a shell
// -- not a same-process function call standing in for it. That matters
// here specifically: this ticket is about verifying, not assuming, how
// these two commands behave under WP-CLI's bootstrap (e.g. whether
// Action Scheduler's async dispatcher fires), so the control has to run
// exactly the way it's invoked in the ticket and in bin/stack's own usage
// text.
// return='all', exit_error=false: capture stdout/stderr/exit code as data
// instead of letting a non-zero exit kill this measurement -- a control
// that exits non-zero is itself something to record (see 'command' in the
// result record), not something to crash on.
$started_at = gmdate( 'c' );
$start_time = microtime( true );

$process = WP_CLI::runcommand(
	$command_argv,
	array(
		'launch'     => true,
		'return'     => 'all',
		'exit_error' => false,
	)
);

$elapsed_seconds = microtime( true ) - $start_time;
$finished_at     = gmdate( 'c' );

if ( '' !== trim( (string) $process->stdout ) ) {
	fwrite( STDERR, "--- control stdout ---\n{$process->stdout}\n" );
}
if ( '' !== trim( (string) $process->stderr ) ) {
	fwrite( STDERR, "--- control stderr ---\n{$process->stderr}\n" );
}

// --- Capture post-run state ------------------------------------------------

$pending_after          = wpcas_probe_pending_count();
$log_messages           = wpcas_probe_log_messages_for_actions( $action_ids );
$probe_records          = wpcas_probe_execution_log_entries();
$cron_in_progress_after = wpcas_probe_cron_in_progress();

$record = wpcas_result_record_build(
	array(
		'control'                => $control,
		'command_argv'           => 'wp ' . $command_argv,
		'command_exit_code'      => (int) $process->return_code,
		// Neither control makes an HTTP request -- both run entirely
		// in-process via WP-CLI (see the module docblock on
		// result-record.php).
		'http_status'            => null,
		'started_at'             => $started_at,
		'finished_at'            => $finished_at,
		'elapsed_seconds'        => $elapsed_seconds,
		'preflight'              => $preflight['snapshot'],
		'pending_before'         => $pending_before,
		'pending_after'          => $pending_after,
		'log_messages'           => $log_messages,
		'probe_records'          => $probe_records,
		'cron_in_progress_after' => $cron_in_progress_after,
	)
);

fwrite(
	STDERR,
	sprintf(
		"Control '%s' finished: %d -> %d pending (drained %d) in %.3fs.\n",
		$control,
		$pending_before,
		$pending_after,
		$record['outcome']['drained'],
		$elapsed_seconds
	)
);

// The result record, and only the result record, goes to STDOUT -- see
// bin/stack's `measure` subcommand, which redirects this script's stdout
// straight to a file under results/.
echo wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
