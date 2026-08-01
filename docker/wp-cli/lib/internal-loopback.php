<?php

declare( strict_types=1 );

/**
 * Issue #29: nginx + PHP-FPM replaced the built-in server, splitting what
 * used to be one process into two Compose services. WP_HOME and
 * WP_SITEURL (docker/Dockerfile) are still a single "localhost:$STACK_PORT"
 * identity -- correct for a real external client, and unchanged from
 * before this ticket -- but nginx, not this php-fpm container, is what
 * actually listens there now. measure-http.php's hand-built loopback URL
 * would otherwise try to connect to this container's own loopback, where
 * nothing answers on that port any more.
 *
 * (The equivalent problem for a `wp_remote_get()`/`wp_remote_post()` call
 * built from `admin_url()` -- measure-async-ajax.php,
 * measure-admin-page-load.php, measure-manual-run.php, and Action
 * Scheduler's own internal async-dispatch loopback, which none of those
 * scripts can special-case since it isn't their code -- is fixed once,
 * globally, in docker/mu-plugins/wpcas-internal-loopback-resolve.php
 * instead, via the `pre_http_request` filter. measure-http.php uses a
 * plain http:// stream, not WP's HTTP API at all, so that filter never
 * sees this request; hence this separate, script-level fix for this one
 * caller.)
 *
 * wpcas_internal_loopback_rewrite() rewrites only the *connection*
 * target -- to nginx's stable Compose service name, immune to this
 * php-fpm container being independently recreated (e.g. by
 * `bin/stack set-disable-wp-cron`, which does exactly that) -- while
 * preserving the original Host header, so WordPress-side logic
 * (is_ssl(), canonical checks, cookie handling) sees the exact same
 * request identity a real external client's would have. This is the
 * same "connect elsewhere, keep the Host header" technique load
 * balancers and reverse proxies use to reach a specific backend directly
 * while leaving the site's own idea of its hostname untouched.
 *
 * @return array{url: string, host_header: string}
 */
function wpcas_internal_loopback_rewrite( string $url ): array {
	$parts = wp_parse_url( $url );

	if ( false === $parts || ! isset( $parts['host'] ) ) {
		WP_CLI::error( "Could not parse host out of internal loopback URL '{$url}'." );
	}

	$host_header = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	// Swap the authority (scheme://host:port) for nginx's Compose service
	// name on its default port 80 -- scheme, path, and query stay exactly
	// what the caller built.
	$rewritten = preg_replace( '#^(https?://)[^/]+#', '${1}nginx', $url, 1 );

	if ( null === $rewritten ) {
		WP_CLI::error( "Could not rewrite internal loopback URL '{$url}' onto the nginx service." );
	}

	return array(
		'url'         => $rewritten,
		'host_header' => $host_header,
	);
}
