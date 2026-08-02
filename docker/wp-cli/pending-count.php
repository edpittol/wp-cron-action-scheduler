<?php

declare( strict_types=1 );

/**
 * Prints the probe hook's current pending count, nothing else.
 *
 * Issue #9's occupancy measurement polls this repeatedly (once per wp-cli
 * invocation) from the host, alongside its own concurrent front-end HTTP
 * requests, to get ground truth for when the triggered drain starts and
 * ends -- polled this way (a plain CLI process) rather than over HTTP so
 * polling itself never consumes one of the PHP-FPM pool children being
 * measured.
 *
 * Usage: wp eval-file docker/wp-cli/pending-count.php
 *
 * Invoked via `bin/stack occupancy`.
 */

require __DIR__ . '/lib/probe.php';

WP_CLI::log( (string) wpcas_probe_pending_count() );
