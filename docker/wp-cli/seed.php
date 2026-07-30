<?php

declare( strict_types=1 );

/**
 * Seeds the probe queue. See docker/wp-cli/lib/probe.php for how and why.
 *
 * Usage: wp eval-file docker/wp-cli/seed.php [<count>]
 *   <count> defaults to WPCAS_PROBE_DEFAULT_SEED_COUNT (50).
 *
 * Invoked via `bin/stack seed [<count>]`.
 */

require __DIR__ . '/lib/probe.php';

/** @var array<int, string> $args Positional args from `wp eval-file`. */
$count = isset( $args[0] ) ? (int) $args[0] : WPCAS_PROBE_DEFAULT_SEED_COUNT;

wpcas_probe_seed( $count );
