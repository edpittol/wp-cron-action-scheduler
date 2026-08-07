<?php

declare( strict_types=1 );

/**
 * Guard: log -- and then block -- HTTP requests to the cron entry point.
 *
 * wp-cron.php is a public, unauthenticated PHP endpoint. Even when the
 * `DISABLE_WP_CRON` constant stops WordPress from spawning its own
 * loopback request to that file on every page load, the file itself
 * stays reachable and executable by anyone who requests it directly.
 *
 * This guard does two separable things to such a request, in this order.
 *
 * 1. RECORD IT, for every non-CLI request that reaches this file --
 *    whatever happens to it next. See "why the log comes first" below.
 * 2. REFUSE IT, but only when the site has already committed to running
 *    cron some other way -- `DISABLE_WP_CRON` is defined and true.
 *
 * The conditions, and why each one is here:
 *
 *   - the cron constant is set    -- DOING_CRON is defined and true.
 *     wp-cron.php defines this before it loads the rest of WordPress
 *     (mu-plugins included), so it is already set by the time this file
 *     runs. It is also the only thing distinguishing a cron request from
 *     any other request in this process.
 *   - the SAPI is not CLI         -- PHP_SAPI is not 'cli'. WP-CLI's own
 *     cron and queue-runner commands must keep working; this guard must
 *     never see them as a request to block, nor log them as suspicious.
 *   - cron is nominally disabled  -- DISABLE_WP_CRON is defined and true.
 *     A site that has not opted out of the default loopback dispatch
 *     still needs wp-cron.php reachable, so the *refusal* is gated on
 *     this constant. The *log* deliberately is not: a site still using
 *     the loopback is exactly where knowing who else calls the endpoint
 *     has value.
 *
 * WHY THE LOG COMES FIRST, AND WHY BOTH HALVES ARE IN ONE FILE
 *
 * The refusal below ends the request, and under PHP-FPM the status it
 * sets is unobservable: core calls fastcgi_finish_request() before it
 * loads WordPress, so the response was already flushed as a 200 before
 * this file existed in the process, and http_response_code() then refuses
 * the call outright -- measured, see
 * docker/fastcgi-isolation/flush-then-status.php. Client, nginx, PHP-FPM
 * and PHP itself all record 200 for a request this guard stopped dead. A
 * log line is therefore the ONLY artefact such a request ever produces.
 * Blocking without logging closes the door and destroys the evidence that
 * anyone tried it.
 *
 * That ordering has to hold, which is why both halves live here rather
 * than in a tidier separate logger: mu-plugins load in alphabetical order
 * by filename (wp_get_mu_plugins() in wp-includes/load.php ends with a
 * plain sort()), so a logger in its own file only runs before this one if
 * its name happens to sort earlier. Keeping them together makes the order
 * a property of the code instead of a property of a filename someone may
 * rename later.
 *
 * WHAT THIS COVERS THAT SECTION 3 DOES NOT
 *
 * Section 3 (30-log-non-cli-canary.php) hooks
 * `action_scheduler_before_process_queue`, so it fires only when Action
 * Scheduler's queue runner runs. But wp-cron.php runs *every* due WP-Cron
 * event, and most sites have a dozen with nothing to do with Action
 * Scheduler (wp_version_check, wp_scheduled_delete, ...). A hit on this
 * endpoint against a quiet Action Scheduler queue trips section 3's
 * canary not at all -- while still costing a full WordPress bootstrap.
 * This guard's line is what records that class of request. The two are
 * complementary; neither substitutes for the other.
 *
 * The marker below is deliberately distinct from section 3's
 * (`[cron-guard] queue run outside CLI:`, matched as a substring by
 * WPCAS_CANARY_LOG_MARKER in docker/wp-cli/lib/canary.php), so the canary
 * parser cannot mistake one for the other. Both share the `[cron-guard]`
 * prefix, so the whole family stays greppable in one pass.
 */

if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON || 'cli' === PHP_SAPI ) {
	return;
}

/*
 * The query string and remote address are attacker-controlled -- this
 * exists to log hostile requests -- so control characters are stripped
 * before they are written, or a crafted request could forge additional
 * log lines and make the log itself untrustworthy. Same reasoning, and
 * the same treatment, as section 3's canary.
 */
$wpcas_clean = static function ( $value ): string {
	return (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value );
};

/*
 * Every WP-Cron hook that was ready to run when this request arrived --
 * Action Scheduler's queue runner among them when it happens to be due,
 * but core's own events too. This is what makes the line answer "what was
 * this request about to run" rather than only "someone knocked".
 *
 * Read before plugins load, so it reflects core's stored schedule rather
 * than anything a plugin might add to the array on the fly through
 * `pre_get_ready_cron_jobs`. wp-includes/cron.php is required at
 * wp-settings.php line 240 and mu-plugins load at 469, so the function is
 * available here; wp-cron.php calls it again for itself further down, and
 * that second read is served from the options cache.
 */
$wpcas_due = array();
foreach ( wp_get_ready_cron_jobs() as $wpcas_hooks ) {
	$wpcas_due = array_merge( $wpcas_due, array_keys( $wpcas_hooks ) );
}

/*
 * `lock` reports core's own `doing_cron` transient, because `due` alone
 * would overstate what this request will do: when the lock is held,
 * wp-cron.php returns without running any of those hooks. The two fields
 * are only meaningful together.
 *
 * `query` matters because core's own loopback always carries
 * `doing_wp_cron=<lock value>` (spawn_cron(), wp-includes/cron.php). An
 * empty query string means nothing internal sent this request.
 */
error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	sprintf(
		'[cron-guard] wp-cron.php reached: ip=%s query=%s lock=%s due=%s',
		isset( $_SERVER['REMOTE_ADDR'] ) ? $wpcas_clean( $_SERVER['REMOTE_ADDR'] ) : 'n/a',
		isset( $_SERVER['QUERY_STRING'] ) ? $wpcas_clean( $_SERVER['QUERY_STRING'] ) : '',
		get_transient( 'doing_cron' ) ? 'held' : 'free',
		array() !== $wpcas_due ? implode( ',', array_unique( $wpcas_due ) ) : 'nothing'
	)
);

if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
	/*
	 * The headers_sent() test is not defensive decoration -- it is the
	 * difference between a clean log and a poisoned one. Called after
	 * core's fastcgi_finish_request(), http_response_code() does not fail
	 * quietly: it emits
	 *
	 *   PHP Warning: http_response_code(): Cannot set response code -
	 *   headers already sent in .../10-block-http-cron.php
	 *
	 * into PHP's error log -- the same file this guard's own line above
	 * goes to. Every blocked request would write a warning next to the
	 * record of itself, which is noise in exactly the place the noise is
	 * least affordable. Suppressing with @ would hide it; asking
	 * headers_sent() first means the call is only attempted where it can
	 * actually work.
	 *
	 * NOT-VERIFIED here: whether a real HTTP client observes a blocked
	 * status under every SAPI/server combination. Under PHP-FPM it
	 * provably does not (see above); the call is kept because under Apache
	 * with mod_php nothing has been flushed yet and the client does get a
	 * real 403. Confirming this guard's live effect means checking whether
	 * the queue actually drained, not reading the status back.
	 */
	if ( ! headers_sent() ) {
		http_response_code( 403 );
	}

	exit;
}
