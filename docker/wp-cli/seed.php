<?php

declare( strict_types=1 );

/**
 * Seeds the probe queue. See docker/wp-cli/lib/probe.php for how and why.
 *
 * Usage: wp eval-file docker/wp-cli/seed.php [<count>] [due-now]
 *   <count>  defaults to WPCAS_PROBE_DEFAULT_SEED_COUNT (50).
 *   due-now  (issue #4) positional argument -- if present, seeds actions
 *            due immediately instead of issue #2's default
 *            ~5-minutes-in-the-future schedule. Needed to measure a
 *            due-now control (`wp cron event run --due-now`,
 *            `wp action-scheduler run`); see wpcas_probe_seed() for why
 *            this can't just be the default.
 *
 * `wp eval-file` only exposes positional args (see EvalFile_Command in
 * wp-cli/eval-command) -- there is no $assoc_args here to attach a real
 * `--due-now` flag to, hence the plain trailing word instead: `bin/stack
 * seed` (issue #9) accepts a real `--due-now` flag and translates it into
 * this positional token before invoking eval-file, so `due-now`'s position
 * relative to `<count>` is not assumed here -- either order works.
 *
 * Invoked via `bin/stack seed [<count>] [--due-now]`.
 */

require __DIR__ . '/lib/probe.php';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$due_now = in_array( 'due-now', $args, true );

$count_args = array_values( array_filter( $args, static fn( string $arg ): bool => 'due-now' !== $arg ) );
$count      = isset( $count_args[0] ) ? (int) $count_args[0] : WPCAS_PROBE_DEFAULT_SEED_COUNT;

wpcas_probe_seed( $count, $due_now );
