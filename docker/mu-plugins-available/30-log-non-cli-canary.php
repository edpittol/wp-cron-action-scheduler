<?php

declare( strict_types=1 );

/**
 * Guard: log a canary on any non-CLI queue run.
 *
 * Action Scheduler's queue runner fires 'action_scheduler_before_process_queue'
 * at the start of every run() call, regardless of what triggered it --
 * WP-Cron, the async loopback, a manual admin run, or WP-CLI. This guard
 * blocks nothing; it is a detection tripwire. If the queue is ever
 * processed by anything other than WP-CLI, a line lands in the PHP error
 * log naming the SAPI, the request URI (when there is one), and the
 * remote address, so that path can be investigated instead of only
 * inferred after the fact.
 *
 * Deliberately not gated on DOING_CRON, DISABLE_WP_CRON, or any other
 * constant: the point of a canary is to catch every non-CLI trigger,
 * including ones none of the other guards in this set happen to cover.
 *
 * The request URI and remote address are attacker-controlled input by
 * definition -- this guard exists precisely to log hostile requests, so
 * it cannot assume either field is well-formed. Both are stripped of
 * control characters (including CR/LF) before being written out, so a
 * crafted request can't forge extra log lines or otherwise inject
 * content into the log stream via this guard.
 */

add_action(
	'action_scheduler_before_process_queue',
	static function () {
		if ( 'cli' === PHP_SAPI ) {
			return;
		}

		$strip_control_chars = static function ( $value ) {
			return (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value );
		};

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $strip_control_chars( $_SERVER['REQUEST_URI'] ) : 'n/a';
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $strip_control_chars( $_SERVER['REMOTE_ADDR'] ) : 'n/a';

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[cron-guard] queue run outside CLI: sapi=%s uri=%s ip=%s',
				PHP_SAPI,
				$request_uri,
				$remote_addr
			)
		);
	}
);
