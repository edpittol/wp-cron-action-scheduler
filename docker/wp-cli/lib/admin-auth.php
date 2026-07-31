<?php

declare( strict_types=1 );

/**
 * Mints an authenticated admin session from inside a WP-CLI process, for
 * issue #7's two vectors (an authenticated wp-admin page load, and a
 * manual run from the Scheduled Actions admin screen) -- both of which
 * require a logged-in request, without a real password being stored
 * anywhere in this repo (acceptance criterion: "the harness can issue
 * authenticated admin requests without storing a real password").
 *
 * docker/entrypoint.sh does set a plaintext `admin`/`admin` password at
 * `wp core install` time (issue #1), because WordPress itself requires
 * *some* password at install -- that is an existing, already-committed
 * fact about this stack, not something this ticket introduces or relies
 * on. What this file avoids is the measurement scripts themselves ever
 * reading, storing, or POSTing that (or any other) password to
 * authenticate their own requests. Instead, it calls wp_set_auth_cookie()
 * -- the same core function a real wp-login.php POST ultimately calls --
 * directly against a user object, producing a real, valid session with no
 * credential ever entering an HTTP request or a config file.
 *
 * wp_set_auth_cookie() itself calls setcookie(), which is a harmless no-op
 * under WP-CLI's SAPI (no real HTTP response to attach a Set-Cookie header
 * to). The actual cookie *values* this file needs are captured instead by
 * hooking the 'set_auth_cookie' / 'set_logged_in_cookie' actions
 * wp_set_auth_cookie() fires immediately before each setcookie() call --
 * both pass the exact cookie string as their first argument, which is
 * genuinely all a real HTTP client needs to present back to authenticate,
 * the same as if it had captured them from a real login response's
 * Set-Cookie headers.
 *
 * The captured logged_in cookie is also written into this same process's
 * own $_COOKIE superglobal (see the end of wpcas_admin_mint_session()
 * below). This is what makes a *second* step -- minting a nonce here for
 * a specific admin action (issue #7's manual-run vector needs one; see
 * docker/wp-cli/measure-manual-run.php) -- produce a nonce that validates
 * for the real, separate HTTP request this session's cookies are handed
 * to. wp_create_nonce() derives its value from wp_get_session_token(),
 * which reads $_COOKIE[LOGGED_IN_COOKIE] (via wp_parse_auth_cookie()) --
 * not any in-memory session state -- so seeding $_COOKIE here with the
 * same value the real request will present is what keeps both
 * computations in agreement.
 */

/**
 * @return array{
 *     user_id: int,
 *     user_login: string,
 *     cookies: array<string, string>,
 * }
 */
function wpcas_admin_mint_session( string $username ): array {
	$user = get_user_by( 'login', $username );

	if ( ! $user ) {
		WP_CLI::error( "No such user '{$username}' -- cannot mint an admin session." );
	}

	$captured = array();

	$capture_auth = static function ( $cookie, $expire, $expiration, $user_id, $scheme, $token ) use ( &$captured ) {
		$captured['auth'] = array(
			'scheme' => $scheme,
			'value'  => $cookie,
		);
	};
	$capture_logged_in = static function ( $cookie, $expire, $expiration, $user_id, $scheme, $token ) use ( &$captured ) {
		$captured['logged_in'] = array(
			'scheme' => $scheme,
			'value'  => $cookie,
		);
	};

	add_action( 'set_auth_cookie', $capture_auth, 10, 6 );
	add_action( 'set_logged_in_cookie', $capture_logged_in, 10, 6 );

	// $remember = false: shortest-lived scheme, plenty for a single
	// measurement run. No password anywhere in this call.
	wp_set_auth_cookie( $user->ID, false );

	remove_action( 'set_auth_cookie', $capture_auth, 10 );
	remove_action( 'set_logged_in_cookie', $capture_logged_in, 10 );

	if ( ! isset( $captured['auth'], $captured['logged_in'] ) ) {
		WP_CLI::error( "wp_set_auth_cookie() did not fire the expected hooks for user '{$username}' -- cannot mint a session." );
	}

	// This stack is plain HTTP (see docker/Dockerfile's WP_HOME/WP_SITEURL),
	// so is_ssl() is false and wp_set_auth_cookie() used the 'auth' scheme
	// (AUTH_COOKIE), not 'secure_auth'. Named directly from what was
	// actually captured, rather than re-deriving is_ssl() here, so this
	// stays correct even if that ever changes.
	$auth_cookie_name      = ( 'secure_auth' === $captured['auth']['scheme'] ) ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
	$logged_in_cookie_name = LOGGED_IN_COOKIE;

	// Feed the minted logged_in cookie back into this same process's own
	// state -- see the module docblock above for why this is what makes a
	// nonce minted here (wp_create_nonce()) agree with verification
	// (wp_verify_nonce()) on the real, separate HTTP request that will
	// present this same cookie.
	$_COOKIE[ $logged_in_cookie_name ] = $captured['logged_in']['value'];
	wp_set_current_user( $user->ID );

	return array(
		'user_id'    => $user->ID,
		'user_login' => $user->user_login,
		'cookies'    => array(
			$auth_cookie_name      => $captured['auth']['value'],
			$logged_in_cookie_name => $captured['logged_in']['value'],
		),
	);
}
