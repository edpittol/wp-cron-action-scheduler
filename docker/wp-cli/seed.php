<?php

declare( strict_types=1 );

/**
 * Seeds the probe queue. See docker/wp-cli/lib/probe.php for how and why.
 *
 * Usage: wp eval-file docker/wp-cli/seed.php [<count>] [due-now]
 *   <count> defaults to WPCAS_PROBE_DEFAULT_SEED_COUNT (50).
 *   due-now schedules actions as already due instead of #2's default
 *   ~5-minute lead time -- see wpcas_probe_seed()'s docstring (issue #9).
 *
 * `due-now` is a positional token, not a `--due-now` flag: `wp eval-file`
 * validates assoc-args strictly against its own command signature and
 * rejects unrecognized `--flags` outright, so bin/stack translates its own
 * `--due-now` flag into this positional token before invoking eval-file.
 *
 * Invoked via `bin/stack seed [<count>] [--due-now]`.
 */

require __DIR__ . '/lib/probe.php';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$due_now = in_array( 'due-now', $args, true );

$count_args = array_values( array_filter( $args, static fn( string $arg ): bool => 'due-now' !== $arg ) );
$count      = isset( $count_args[0] ) ? (int) $count_args[0] : WPCAS_PROBE_DEFAULT_SEED_COUNT;

wpcas_probe_seed( $count, $due_now );
