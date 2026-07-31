#!/usr/bin/env php
<?php

declare( strict_types=1 );

/**
 * Thin CLI wrapper around wpcas_occupancy_build_record() (issue #9).
 *
 * Reads the raw facts `bin/stack occupancy` gathered (concurrent front-end
 * request timings, drain pending-count samples, the trigger request's own
 * timing) as JSON on stdin, and prints the finished record -- response
 * times and worker occupancy across the drain window, the trigger's own
 * response time kept separate from the drain duration, and the server
 * model named explicitly -- as JSON on stdout.
 *
 * Deliberately a plain host-side PHP script, not a `wp eval-file` script:
 * wpcas_occupancy_build_record() has no WordPress dependency (see its own
 * docstring), and `bin/stack occupancy` already runs the concurrent
 * front-end requests from the host (real external HTTP clients hitting
 * the stack's published port, not requests made from inside the
 * container), so keeping this step on the host too avoids a redundant
 * container round-trip for a computation that doesn't need one.
 *
 * Usage: php bin/occupancy-report.php < facts.json
 */

require __DIR__ . '/../docker/wp-cli/lib/occupancy-assertions.php';

$raw = stream_get_contents( STDIN );
if ( false === $raw || '' === trim( (string) $raw ) ) {
	fwrite( STDERR, "occupancy-report: no input on stdin.\n" );
	exit( 1 );
}

$facts = json_decode( $raw, true );
if ( ! is_array( $facts ) ) {
	fwrite( STDERR, 'occupancy-report: could not parse stdin as a JSON object: ' . json_last_error_msg() . "\n" );
	exit( 1 );
}

$record = wpcas_occupancy_build_record( $facts );

echo wp_json_encode_fallback( $record ), "\n";

/**
 * A tiny stand-in for wp_json_encode() -- this script runs outside any
 * WordPress bootstrap on purpose (see the docstring above).
 */
function wp_json_encode_fallback( array $data ): string {
	return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}
