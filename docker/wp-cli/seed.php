<?php

declare( strict_types=1 );

/**
 * Seeds the probe queue. See docker/wp-cli/lib/probe.php for how and why.
 *
 * Usage: wp eval-file docker/wp-cli/seed.php [<count>] [due-now]
 *   <count>  defaults to WPCAS_PROBE_DEFAULT_SEED_COUNT (50).
 *   due-now  (issue #4) literal second positional argument -- if present,
 *            seeds actions due immediately instead of issue #2's default
 *            ~5-minutes-in-the-future schedule. Needed to measure a
 *            due-now control (`wp cron event run --due-now`,
 *            `wp action-scheduler run`); see wpcas_probe_seed() for why
 *            this can't just be the default.
 *
 * `wp eval-file` only exposes positional args (see EvalFile_Command in
 * wp-cli/eval-command) -- there is no $assoc_args here to attach a real
 * `--due-now` flag to, hence the plain trailing word instead.
 *
 * Invoked via `bin/stack seed [<count>] [due-now]`.
 */

require __DIR__ . '/lib/probe.php';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$count   = isset( $args[0] ) ? (int) $args[0] : WPCAS_PROBE_DEFAULT_SEED_COUNT;
$due_now = isset( $args[1] ) && 'due-now' === $args[1];

wpcas_probe_seed( $count, $due_now );
