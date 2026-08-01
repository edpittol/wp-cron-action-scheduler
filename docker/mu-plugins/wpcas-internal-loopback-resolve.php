<?php

declare( strict_types=1 );

/**
 * Issue #29: nginx + PHP-FPM replaced the built-in server, splitting what
 * used to be one process into two Compose services. WP_HOME and
 * WP_SITEURL (docker/Dockerfile) are still a single "localhost:$STACK_PORT"
 * identity -- correct for a real external client -- but nginx, not this
 * php-fpm container, is what actually listens there now. Any internal
 * HTTP loopback built from that identity (admin_url(), site_url(), and
 * anything derived from them) would otherwise try to connect to this
 * container's own loopback, where nothing answers on that port any more.
 *
 * This is not just this repo's own measurement scripts' problem: Action
 * Scheduler's own async-request dispatcher
 * (WP_Async_Request::dispatch(), called from
 * ActionScheduler_QueueRunner::maybe_dispatch_async_request() on every
 * is_admin() request) builds its own loopback the same way, and this
 * repo's guard section 2 and several scenarios (async-ajax-*,
 * admin-page-load-*) depend on that real dispatch actually firing or
 * being suppressed -- code this repo doesn't own and can't edit. Fixing
 * this once, globally, here, is what lets those scenarios (and
 * docker/wp-cli/measure-async-ajax.php, measure-admin-page-load.php,
 * measure-manual-run.php, all three of which go through WordPress's own
 * HTTP API via admin_url()) keep working unmodified, with no
 * special-casing of their own. docker/wp-cli/measure-http.php is the one
 * exception: it uses a plain http:// stream, not WP's HTTP API, so this
 * filter never sees its request -- see that script's own fix,
 * lib/internal-loopback.php, which solves the identical problem the same
 * way for that one caller.
 *
 * First attempt (kept here as a note, not code, because the failure mode
 * is worth knowing if this ever needs revisiting): CURLOPT_RESOLVE via
 * the `http_api_curl` action, the textbook "connect elsewhere, keep the
 * Host header" technique. It does not work for a "localhost" target
 * specifically -- curl has treated "localhost" as a hardcoded loopback
 * name since 7.78.0, resolved straight to 127.0.0.1/::1 without
 * consulting CURLOPT_RESOLVE (or any DNS) at all, as a deliberate
 * anti-DNS-rebinding protection. Confirmed directly against this image's
 * curl before writing the approach below.
 *
 * What actually works: `pre_http_request`, which runs before WP_Http
 * picks a transport at all. For any request targeting this site's own
 * siteurl host, this re-issues the *same* request against nginx's stable
 * Compose service name instead (immune to this php-fpm container being
 * independently recreated, e.g. by `bin/stack set-disable-wp-cron`,
 * which does exactly that) with an explicit Host header preserving the
 * original host:port, so WordPress-side logic on the far end (is_ssl(),
 * canonical checks, cookie handling) sees the exact same request identity
 * a real external client's would have. The rewritten request's own host
 * ("nginx") no longer matches this site's siteurl host, so the recursive
 * wp_remote_request() call below does not re-enter this filter.
 */
add_filter(
	'pre_http_request',
	static function ( $preempt, $parsed_args, $url ) {
		$target = wp_parse_url( $url );

		if ( ! is_array( $target ) || ! isset( $target['host'] ) ) {
			return $preempt;
		}

		$home = wp_parse_url( home_url() );

		if ( ( $home['host'] ?? null ) !== $target['host'] ) {
			return $preempt;
		}

		$host_header = $target['host'] . ( isset( $target['port'] ) ? ':' . $target['port'] : '' );

		$rewritten_url = preg_replace( '#^(https?://)[^/]+#', '${1}nginx', $url, 1 );

		if ( null === $rewritten_url ) {
			return $preempt;
		}

		$parsed_args['headers']['Host'] = $host_header;

		return wp_remote_request( $rewritten_url, $parsed_args );
	},
	10,
	3
);
