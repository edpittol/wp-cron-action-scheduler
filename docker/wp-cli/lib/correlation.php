<?php

declare( strict_types=1 );

/**
 * Pure correlation logic for issue #33 -- joins one measured request's
 * web-server-observed status and process-manager-observed status out of two
 * independently-written access logs, by the `X-Wpcas-Request-Id` header the
 * request carried (see docker/wp-cli/measure-http.php), never by line order
 * or proximity in either log.
 *
 * Deliberately free of any WordPress/network/filesystem dependency -- same
 * split as lib/http-status.php vs. measure-http.php, and
 * lib/preflight-assertions.php vs. lib/probe.php: this file only parses and
 * joins raw lines it is handed; reading nginx's and PHP-FPM's actual access
 * log files lives in measure-http.php. See tests/correlation.test.php for
 * plain-`php` coverage, including the "two requests interleave across the
 * two logs" case this module exists to get right.
 *
 * Both access logs are configured (docker/nginx/default.conf's `wpcas`
 * log_format; docker/php-fpm/access-log.conf's `access.format`) to emit the
 * same "request_id=<id> status=<code>" shape, whitespace-separated from
 * whatever else each format also includes -- so one line parser
 * (wpcas_correlation_parse_line()) handles lines from either source.
 */

/**
 * Parses one access log line for its `request_id` and `status` tokens.
 *
 * Returns null (never fabricates a value) when the line has no
 * `request_id=` token, or that token's value is nginx's own placeholder for
 * an absent header ("-"), or empty -- any of these mean the line isn't one
 * this correlator can join on, not that the line is malformed. A request
 * with no `X-Wpcas-Request-Id` header (any request this repo's own tooling
 * did not issue -- e.g. WP-CLI's internal HTTP checks, or a stray browser
 * hit) is expected to look exactly like this.
 */
function wpcas_correlation_parse_line( string $line ): ?array {
	if ( ! preg_match( '/request_id=(?<id>\S+)\s+status=(?<status>\d{3})/', $line, $matches ) ) {
		return null;
	}

	if ( '' === $matches['id'] || '-' === $matches['id'] ) {
		return null;
	}

	return array(
		'request_id' => $matches['id'],
		'status'     => (int) $matches['status'],
	);
}

/**
 * Indexes a raw list of access log lines from one source by request id,
 * discarding lines that don't carry one (see wpcas_correlation_parse_line()).
 *
 * Last line wins for a repeated id: not expected in practice (this repo's
 * measured requests are issued one at a time, each with its own id -- see
 * measure-http.php), but keeps this function total rather than asserting
 * uniqueness that isn't actually this file's to enforce.
 *
 * @param string[] $lines
 * @return array<string, int> request_id => status
 */
function wpcas_correlation_index_by_request_id( array $lines ): array {
	$index = array();

	foreach ( $lines as $line ) {
		$parsed = wpcas_correlation_parse_line( $line );

		if ( null === $parsed ) {
			continue;
		}

		$index[ $parsed['request_id'] ] = $parsed['status'];
	}

	return $index;
}

/**
 * Correlates one request id's pair of independently-recorded statuses --
 * the web server's (nginx) and the process manager's (PHP-FPM) -- out of
 * two raw sets of access log lines.
 *
 * The join key is the request id alone. Neither $web_lines nor $fpm_lines
 * needs to be in any particular order, and neither list needs to contain
 * only one request's lines -- a real run's logs carry every request an
 * armed/unarmed scenario issued, not just the one this call cares about.
 * This is what makes the result correct when two requests' lines
 * interleave across the two logs (e.g. request B's nginx line lands before
 * request A's, because A's PHP-FPM worker took longer to log its own line)
 * -- see tests/correlation.test.php's interleaving case, which is exactly
 * the failure mode a "nearest line" or "next line" heuristic would get
 * wrong.
 *
 * Either status is independently null when no line for this request id was
 * found in that source -- e.g. the web server's log rotated the line away,
 * or the process manager's access log was disabled for this run. Never
 * fabricates a value, and never falls back to the other source's status.
 *
 * @param string[] $web_lines Raw nginx access log lines.
 * @param string[] $fpm_lines Raw PHP-FPM access log lines.
 * @return array{web_status: int|null, fpm_status: int|null}
 */
function wpcas_correlation_find_pair( string $request_id, array $web_lines, array $fpm_lines ): array {
	$web_index = wpcas_correlation_index_by_request_id( $web_lines );
	$fpm_index = wpcas_correlation_index_by_request_id( $fpm_lines );

	return array(
		'web_status' => $web_index[ $request_id ] ?? null,
		'fpm_status' => $fpm_index[ $request_id ] ?? null,
	);
}
