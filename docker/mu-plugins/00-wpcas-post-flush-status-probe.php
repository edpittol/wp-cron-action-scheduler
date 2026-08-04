<?php

declare( strict_types=1 );

/**
 * Records the POST-FLUSH status of a measured request -- the status the
 * application ended up setting after its response had already been closed
 * to the client, which by definition no client can ever read back.
 *
 * Issue #35 (the masking finding) asks what status the armed cron entry
 * point ends up with on the server side, once the response the client got
 * has already been closed. Three sources were checked against this running
 * stack rather than reasoned about:
 *
 *   - nginx logs 200 (docker/nginx/default.conf's `wpcas` log_format) --
 *     correctly, since 200 is genuinely what it sent to the client.
 *   - PHP-FPM logs 200 as well (docker/php-fpm/access-log.conf's
 *     `access.format` `%s`). MEASURED, not assumed: with guard section 1
 *     armed, a request whose guard calls http_response_code( 403 ) and
 *     exits still produced `status=200` in PHP-FPM's own access log. The
 *     process manager records the status that was FLUSHED.
 *   - This probe reads PHP's own status back at shutdown, and it reports
 *     200 too.
 *
 * That third reading is the point, and it is a NEGATIVE result worth having
 * rather than a gap: the guard's 403 does not exist anywhere at runtime.
 * The reason is the same ordering the isolation proof measures directly
 * (docker/fastcgi-isolation/flush-then-status.php): after
 * fastcgi_finish_request(), http_response_code( 403 ) RETURNS FALSE and
 * leaves the status at 200 -- PHP refuses the change outright, so there is
 * no later status for any log to record. "You cannot return an HTTP status
 * from a mu-plugin on wp-cron.php" turns out to be literal: not merely
 * unreadable by the client, but never set at all.
 *
 * Which is why this probe stays, having found nothing: without it, a
 * reader would have to take that on trust. With it, every armed
 * cron-entry-point record carries the read-back that rules out a hidden
 * server-side 403.
 *
 * This is deliberately NOT one of the guard sections in
 * docker/mu-plugins-available/ -- those stay production-shaped, with no
 * instrumentation of their own (issue #3's acceptance criterion), and this
 * file must not change what any guard does. It is loaded unconditionally
 * from docker/mu-plugins/, the same way wpcas-poc-probe.php and
 * wpcas-dispatch-decision-probe.php already are: probe infrastructure that
 * measurements depend on has no arm/disarm step to forget.
 *
 * Filename starts with `00-` for one reason: mu-plugins load in filename
 * order, and guard section 1 (`10-block-http-cron.php`) ends the request
 * with `exit` the moment it decides to block. A probe that loaded after it
 * would never load at all on precisely the request this exists to observe.
 * Loading first means the shutdown function below is already registered
 * when that `exit` runs -- PHP runs registered shutdown functions on
 * `exit`, which is what makes the post-flush status observable at all.
 *
 * Scoped to requests this repo's own tooling issued, by the presence of the
 * `X-Wpcas-Request-Id` header (docker/wp-cli/measure-http.php sends it;
 * lib/correlation.php joins on it). An ordinary browser hit, a stray
 * scanner, or WordPress's own internal loopbacks carry no such header and
 * are never recorded here -- this log stays a record of measured requests
 * only, joinable by exactly the same key as the two access logs.
 */

// The status is written in the same `request_id=<id> status=<code>` shape
// nginx's and PHP-FPM's access logs already use, so one parser
// (wpcas_correlation_parse_line(), docker/wp-cli/lib/correlation.php)
// reads all three sources and one join key covers all three. Its own file,
// not php-error.log: this is an observation, not an error, and mixing it
// into a log that also carries PHP's deprecation noise would make the
// parser's job a filtering problem rather than a joining one -- the same
// separation issue #34's isolation proof already applies to its own log.
const WPCAS_POST_FLUSH_STATUS_LOG = '/var/log/wpcas/post-flush-status.log';

if ( ! empty( $_SERVER['HTTP_X_WPCAS_REQUEST_ID'] ) ) {
	register_shutdown_function(
		static function (): void {
			// http_response_code() with no argument reads the status PHP
			// currently holds for this request -- after
			// fastcgi_finish_request() has already sent an earlier one, and
			// after a guard's own http_response_code(403) call, this is the
			// later value: exactly the "genuinely correct server-side, but
			// unreadable by any client" status CONTEXT.md defines as the
			// post-flush status.
			$status = http_response_code();

			if ( ! is_int( $status ) ) {
				// http_response_code() returns false outside a web SAPI
				// (e.g. if this file were ever loaded under WP-CLI, which
				// carries no request id header and so should not reach
				// here in the first place). Recording nothing is correct:
				// this probe never fabricates a status.
				return;
			}

			// `flushed=` is recorded alongside, from PHP's own view of
			// whether the response had already been sent by the time this
			// shutdown function ran. It is what distinguishes "the client
			// could not have read this status" (flushed=1: headers were
			// already gone, so this really is a post-flush status) from
			// "this simply is the status the client got" (flushed=0, e.g.
			// the fully unarmed control) -- so a reader of the log alone
			// can tell those two apart without inferring it from the
			// scenario's name.
			$line = sprintf(
				"request_id=%s status=%d flushed=%d time=%s\n",
				(string) $_SERVER['HTTP_X_WPCAS_REQUEST_ID'],
				$status,
				headers_sent() ? 1 : 0,
				gmdate( 'c' )
			);

			// Appended, never truncated: one line per measured request, and
			// the correlator joins by id rather than reading "the last
			// line", so accumulated lines from earlier scenarios are
			// harmless (see lib/correlation.php's interleaving case).
			// Errors are deliberately not escalated -- a probe that cannot
			// write must not change the outcome of the measurement it is
			// observing; measure-http.php reports a missing post-flush
			// status as null rather than trusting a fabricated one.
			@file_put_contents( WPCAS_POST_FLUSH_STATUS_LOG, $line, FILE_APPEND ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- see above: a probe must never alter the request it observes, and WP_Filesystem is not available this early (mu-plugin load, pre-init).
		}
	);
}
