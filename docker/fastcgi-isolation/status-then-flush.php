<?php

declare( strict_types=1 );

/**
 * Issue #34: the control half of the isolated fastcgi_finish_request()
 * proof (see flush-then-status.php's own docblock for the pair's shared
 * purpose and the full picture this file is one side of).
 *
 * Identical to flush-then-status.php except for one thing: the *order*
 * of the two operations.
 *
 *   1. Sets an observable status of 403 via http_response_code() BEFORE
 *      anything is flushed -- an ordinary, un-masked status change, no
 *      different from any plain PHP script that 403s a request.
 *   2. Records that same 403 to the server-side log, for symmetry with
 *      flush-then-status.php's own record -- both files always record
 *      403 server-side; only the client-observable status differs
 *      between them (see docker/wp-cli/measure-fastcgi-isolation.php).
 *   3. Only then calls fastcgi_finish_request(), flushing and closing the
 *      response -- harmlessly, since there is nothing left to send that
 *      the client hasn't already received.
 *
 * This file's whole point is negative: if its own observable status were
 * NOT a real, client-visible 403, that would mean the status pathway
 * itself (http_response_code() reaching the client at all) was broken --
 * and flush-then-status.php's divergence would prove nothing, since there
 * would be no baseline "status change without a masking flush" run to
 * compare it against. This file being an ordinary, correctly-observed 403
 * is what makes the sibling file's divergence attributable to
 * fastcgi_finish_request()'s ordering alone.
 */

const WPCAS_FASTCGI_ISOLATION_LOG = '/var/log/wpcas/fastcgi-isolation.log';

$set_call_returned = http_response_code( 403 );

// Read back for the same reason its sibling does (issue #35): so this
// control's own log line is an observation rather than a restatement of the
// literal above. Here the read-back agrees with the attempt (403), because
// nothing has been flushed yet -- and that agreement is precisely the
// baseline that makes flush-then-status.php's disagreement attributable to
// the ordering alone.
$status_after_attempt = http_response_code();

$entry = array(
	'file'              => 'status-then-flush',
	'attempted_status'  => 403,
	'set_call_returned' => $set_call_returned,
	'post_flush_status' => $status_after_attempt,
	'timestamp'         => gmdate( 'c' ),
	'pid'               => getmypid(),
);

// See flush-then-status.php's own comment on why plain json_encode() and
// not wp_json_encode() is used here.
file_put_contents( WPCAS_FASTCGI_ISOLATION_LOG, json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );

echo "Status was set before this response was flushed and closed.\n";

if ( function_exists( 'fastcgi_finish_request' ) ) {
	fastcgi_finish_request();
}
