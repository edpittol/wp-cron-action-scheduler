<?php

declare( strict_types=1 );

/**
 * Router for PHP's built-in web server.
 *
 * Reproduces the nginx semantics WordPress expects:
 *
 *     try_files $uri $uri/ /index.php?$args;
 *
 * i.e.: serve the requested path directly if it maps to an existing file
 * (including executing .php files in place, such as wp-cron.php,
 * wp-login.php, or anything under wp-admin/); otherwise, including for
 * pretty-permalink paths that don't correspond to a file on disk, hand off
 * to the front controller.
 *
 * Known gap: nginx configs for WordPress normally add an explicit
 * `location ~ /\.ht` (or similar) deny rule so dotfiles -- including the
 * SQLite data file this stack keeps at wp-content/database/.ht.sqlite --
 * are never served. This router has no equivalent: any existing file
 * under the web root, dotfile or not, is handed to the built-in server as
 * static content (see the is_file() branch below), so the live database
 * is downloadable over HTTP. Acceptable for a localhost-only PoC; would
 * need an explicit deny-by-path check here before this harness is used
 * anywhere reachable beyond localhost.
 */

$root = __DIR__;
$path = urldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

if ( '' === $path ) {
	$path = '/';
}

$requested = realpath( $root . $path );

// try_files $uri: an existing file under the web root wins, whatever it is.
// Returning false tells the built-in server to serve/execute it itself,
// which covers both static assets and directly requested PHP endpoints.
if (
	false !== $requested
	&& 0 === strpos( $requested, $root . DIRECTORY_SEPARATOR )
	&& is_file( $requested )
) {
	return false;
}

// try_files ... /index.php?$args: no file matched (this also covers the
// $uri/ case, since WordPress never relies on directory listings), so fall
// back to the front controller with the original query string intact.
chdir( $root );
require $root . '/index.php';
