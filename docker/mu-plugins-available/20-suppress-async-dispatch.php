<?php

declare( strict_types=1 );

/**
 * Guard: suppress Action Scheduler's async dispatch.
 *
 * Action Scheduler's queue runner listens on the 'shutdown' hook and,
 * when it judges the request eligible, fires a loopback POST to
 * admin-ajax.php (action=as_async_request_queue_runner) to keep draining
 * the queue without waiting for the next WP-Cron tick. That dispatch is
 * itself gated by the 'action_scheduler_allow_async_request_runner'
 * filter, which Action Scheduler already consults before sending the
 * request. Forcing that filter to false stops the dispatch outright,
 * whatever else made the request look eligible (pending actions due, no
 * concurrent claim in progress, and so on).
 *
 * This closes only the async-loopback entry point. It says nothing about
 * wp-cron.php itself, a direct POST to the async endpoint from outside
 * WordPress, or a manual run from the Action Scheduler admin screen --
 * each of those is covered, if at all, by one of the other guards in this
 * set, independently.
 */

add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
