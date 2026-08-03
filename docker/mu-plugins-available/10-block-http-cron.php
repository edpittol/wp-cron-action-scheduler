<?php

declare( strict_types=1 );

/**
 * Guard: block HTTP requests to the cron entry point.
 *
 * wp-cron.php is a public, unauthenticated PHP endpoint. Even when the
 * `DISABLE_WP_CRON` constant stops WordPress from spawning its own
 * loopback request to that file on every page load, the file itself
 * stays reachable and executable by anyone who requests it directly.
 * This guard ends that request before the queue can be drained, but only
 * when all three of the following hold:
 *
 *   - the cron constant is set    -- DOING_CRON is defined and true.
 *     wp-cron.php defines this before it loads the rest of WordPress
 *     (mu-plugins included), so it is already set by the time this file
 *     runs.
 *   - the SAPI is not CLI         -- PHP_SAPI is not 'cli'. WP-CLI's own
 *     cron and queue-runner commands must keep working; this guard must
 *     never see them as a request to block.
 *   - cron is nominally disabled  -- DISABLE_WP_CRON is defined and true.
 *     A site that has not opted out of the default loopback dispatch
 *     still needs wp-cron.php reachable; this guard only removes the web
 *     entry point once that site has already committed to running cron
 *     some other way.
 *
 * All three must hold together. Dropping any one of them either blocks
 * WP-CLI, or removes the only cron path a site has.
 *
 * NOT-VERIFIED here: whether a real HTTP client observes a blocked status
 * for this request under every SAPI/server combination. Some server and
 * PHP configurations can flush and close the response to the client
 * before mu-plugin code such as this one ever runs, which would make a
 * "clean" status code observed by the client misleading either way -- a
 * 200 is not proof this guard failed, and a 403 is not proof it isn't
 * bypassable some other way. Confirming this guard's live effect requires
 * checking whether the action queue actually drained from an unauthorized
 * request, not just reading the HTTP status back; that check belongs to
 * the tickets that exercise this guard against a live request.
 *
 * Issue #35 is that ticket, for the nginx + PHP-FPM server model (issue
 * #29): core's wp-cron.php calls fastcgi_finish_request() before this file
 * even loads, which flushes and closes the response to the client as an
 * already-sent 200 before the `http_response_code( 403 )` call below ever
 * runs -- confirmed live, on this stack, both statuses land in the client
 * response and in nginx's and PHP-FPM's own access logs (docker/wp-cli/lib/
 * correlation.php's `server_observed`) alike, with no trace of the 403
 * this guard actually set. That leaves the pending-count delta (0 drained)
 * as the only other observable proof this guard ran at all -- the
 * error_log() call immediately below is what turns "0 drained" into
 * "0 drained *and* this exact guard is what set it", by writing the status
 * this guard set to a destination unaffected by that flush (a plain file,
 * not the client connection or either access log) -- see docker/wp-cli/
 * lib/guard-block.php for how that line is read back and folded into the
 * result record's `post_flush_status` field.
 */

if (
	defined( 'DOING_CRON' ) && DOING_CRON
	&& 'cli' !== PHP_SAPI
	&& defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
) {
	http_response_code( 403 );
	error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- message_type=3: a fixed file, deliberately independent of php.ini's `error_log` directive (docker/Dockerfile's own guard-block.log docblock explains why).
		'[cron-guard] http entry point blocked post-flush: status=403',
		3,
		'/var/log/wpcas/guard-block.log'
	);
	exit;
}
