# Run WP-Cron and Action Scheduler from WP-CLI, and close the doors behind it

WordPress runs your scheduled work inside a web request unless you stop it. A visitor loads a page, WordPress fires a loopback request to `wp-cron.php`, and every due event runs in there — core's own housekeeping, whatever your plugins scheduled, and, if you have Action Scheduler installed, a queue drain along with them. Action Scheduler then goes one step further: it has its own loopback dispatcher, so it can keep draining long after the visitor who triggered it has gone.

Most of the time that is fine. When it isn't — when scheduled work competes with real traffic, or when you want one place where it runs and one log that records it — the fix is to move cron onto a system scheduler and close the web paths behind it.

**Two layers, one entry point.** WP-Cron is WordPress's scheduler: a stored list of due events, and a web request that fires them. Action Scheduler is a plugin that registers *one* of those events — `action_scheduler_run_queue` — and drains its own queue when that event fires. It is not a system parallel to WP-Cron; it sits on top of it. So everything below about `wp-cron.php`, the loopback, and the constant that is supposed to disable it applies to core's events exactly as it applies to the queue. Where Action Scheduler needs a step of its own, it gets one, and it is named as such.

This post does that in five steps, then explains what each step is actually doing and why it takes the form it does. Every step is a file you can paste or a command you can run.

**What this post is not about.** How long a run takes, how many PHP workers it occupies, or how much front-end latency it costs is out of scope here. Those numbers depend entirely on your workload and your pool, and they deserve their own measurements rather than borrowed ones. The question here is narrower and more portable: **which doors can reach your scheduled work, and what happens when you close each one.** Where §1 gets into what an open door costs you, it names the mechanism and stops there — the numbers stay yours to measure.

Everything below was measured on WordPress 7.0.2 with Action Scheduler 4.0.0, served by nginx and PHP-FPM, against a queue of 50 pending actions on a single probe hook. Where a number appears, it is a count, a status, or a hook state — never a duration, with one deliberate exception that is labelled where it appears.

**Why every number is an Action Scheduler number.** The queue is the instrument, not the subject. A pending count is a fact you can read immediately before a request and immediately after it, and each action records which process executed it. Core's own events give you nothing that precise: they fire once, reschedule themselves, and mostly leave no artefact to count. So the queue is what gets measured — and where a result turns on the entry point rather than on what sat behind it, it holds for core's events too, because the endpoint runs every due event and the queue drain is only one line on that list.

---

# Part I — Do it

## What you'll end up with

```
system cron ──> wp cron event run --due-now ──┬─> WordPress core's own events
                                              └─> the Action Scheduler queue

nginx        ──> /wp-cron.php ................ 403, PHP never starts
WordPress    ──> /wp-cron.php reaching PHP ... logged, then 403
WordPress    ──> async loopback dispatch ..... suppressed
WordPress    ──> action_scheduler_run_queue .. no callback attached, except under WP-CLI
WordPress    ──> any non-CLI queue run ....... logged
admin UI     ──> the "Run" row action ........ still works, on purpose
```

One scheduled command, three closed doors, one left open deliberately, two tripwires. `wp cron event run --due-now` fires the same hook `wp-cron.php` would have fired, from a process that step 4 deliberately exempts — so core's own events and the Action Scheduler queue both drain from that single entry, and there is one place to look when something doesn't run.

The two tripwires watch different things, and both are needed. The **entry-point tripwire** in step 2b records that someone reached `wp-cron.php` at all, whatever they were after. The **queue tripwire** in step 4 records that Action Scheduler's queue ran outside WP-CLI. A hit on the endpoint that runs only core's own events — a version check, a transient cleanup — trips the first and not the second.

The order of the steps matters: step 1 is what makes step 2b safe, and step 4's WP-CLI exemption is what keeps step 5 working at all.

## Step 1 — Stop WordPress from dispatching cron itself

In `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

This stops WordPress from firing its own loopback request to `wp-cron.php` at the end of a page load. It does **not** make `wp-cron.php` unreachable — that's step 2, and the reason why is §1.

## Step 2a — Close the web entry point in front of PHP

In your nginx server block:

```nginx
# Must come before any `location ~ \.php$` block. nginx matches an exact
# `location =` ahead of every regex location, so this is what serves this
# one URI.
location = /wp-cron.php {
    return 403;
}
```

Reload nginx. Then check it — this is the one step in this post you can verify by reading a status code:

```bash
curl -is https://example.com/wp-cron.php | head -1
```

```
HTTP/1.1 403 Forbidden
```

PHP never starts for that request. Nothing bootstraps, no worker is consumed, and nothing runs — no core event, no queue drain.

> **If you are deploying one config across many sites, read §3 before you ship this.** nginx cannot read a PHP constant, so this block is unconditional: it also blocks sites that still depend on the web loopback, and those sites lose cron entirely. That is measured, not hypothetical.

## Step 2b — Log every hit, then close it again inside WordPress

Create `wp-content/mu-plugins/cron-web-entry-point.php`:

```php
<?php
/**
 * Log — and then refuse — HTTP requests to the cron entry point.
 *
 * Second layer behind the web-server block: this one catches a request
 * that reaches PHP by some route the web server config doesn't cover —
 * another vhost, a rewrite, an environment where that config wasn't
 * deployed.
 *
 * The log line comes FIRST, deliberately. The refusal below ends the
 * request, and §2 explains why the status it returns is invisible: on
 * PHP-FPM the response was already flushed before this file loaded. A
 * log line is the only trace this request will ever leave. Blocking
 * without logging would close the door and destroy the evidence.
 *
 * wp-cron.php defines DOING_CRON before mu-plugins load, and it die()s
 * on any request with a body, so everything reaching this point is a GET
 * aimed at the cron endpoint. WP-CLI is excluded: that is the path we
 * are keeping.
 */

if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON || 'cli' === PHP_SAPI ) {
	return;
}

$clean = static function ( $value ) {
	return (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value );
};

$due = array();
foreach ( wp_get_ready_cron_jobs() as $hooks ) {
	$due = array_merge( $due, array_keys( $hooks ) );
}

error_log(
	sprintf(
		'[cron-guard] wp-cron.php reached: ip=%s query=%s lock=%s due=%s',
		isset( $_SERVER['REMOTE_ADDR'] ) ? $clean( $_SERVER['REMOTE_ADDR'] ) : 'n/a',
		isset( $_SERVER['QUERY_STRING'] ) ? $clean( $_SERVER['QUERY_STRING'] ) : '',
		get_transient( 'doing_cron' ) ? 'held' : 'free',
		$due ? implode( ',', array_unique( $due ) ) : 'nothing'
	)
);

if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
	if ( ! headers_sent() ) {
		http_response_code( 403 );
	}

	exit;
}
```

Two real lines from that file, one from core's own loopback on a site still using it, one from a bare external request:

```
[05-Aug-2026 20:11:19 UTC] [cron-guard] wp-cron.php reached: ip=172.18.0.2 query=doing_wp_cron=1785960679.6381549835205078125000 lock=held due=recovery_mode_clean_expired_keys,wp_privacy_delete_old_export_files,wp_version_check,wp_update_plugins,wp_update_themes,action_scheduler_run_queue,wp_site_health_scheduled_check
[05-Aug-2026 20:10:06 UTC] [cron-guard] wp-cron.php reached: ip=172.18.0.2 query= lock=free due=recovery_mode_clean_expired_keys,wp_privacy_delete_old_export_files,wp_version_check,wp_update_plugins,wp_update_themes,action_scheduler_run_queue,wp_site_health_scheduled_check
```

The second line is the one worth an alert: no `doing_wp_cron` key, so nothing internal sent it, and the lock was free, so core was about to run all seven of those hooks. Both requests here came from inside the same container network, which is why `ip=` doesn't separate them — on a real site an outside hit carries an outside address. `query=` and `lock=` are what tell the two apart regardless.

Note what `due=` contains. Six of those seven hooks are core's own — version checks, a privacy cleanup, a site-health check — and only one belongs to Action Scheduler. A request that runs just the other six drains no queue at all, so nothing downstream of Action Scheduler would ever notice it happened.

Both layers can be armed at once. When nothing is wrong, the whole file costs one `defined()` check.

> **Why the log and the block live in one file.** Splitting them looks tidier and quietly breaks: mu-plugins load in alphabetical order by filename — `wp_get_mu_plugins()` ends with a plain `sort()` — so a logger in a separate file only runs first if its name happens to sort earlier. Keeping both in one file, log before block, makes the order a property of the code rather than of a filename someone may rename later.

## Step 3 — Suppress Action Scheduler's async dispatch

Create `wp-content/mu-plugins/cron-suppress-async-dispatch.php`:

```php
<?php
/**
 * Stop Action Scheduler from opening its own loopback door.
 *
 * On 'shutdown', Action Scheduler decides whether to POST to
 * admin-ajax.php?action=as_async_request_queue_runner to keep draining
 * without waiting for the next cron tick. This filter answers no.
 *
 * This stops WordPress from *sending* that request. It does not gate the
 * endpoint against someone sending an equivalent request directly — see §4.
 */

add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
```

## Step 4 — Unhook the queue runner outside WP-CLI

Create `wp-content/mu-plugins/cron-unhook-queue-runner.php`:

```php
<?php
/**
 * Detach Action Scheduler's queue runner from the WP-Cron hook.
 *
 * Action Scheduler attaches its own run() method to
 * 'action_scheduler_run_queue' from its 'init' priority-1 callback.
 * Removing it turns WP-Cron, the async loopback, and any direct call to
 * that hook into no-ops: there is no callback left to run.
 *
 * Priority 100 is not decoration. remove_action() only works if it runs
 * *after* the matching add_action(), at the same priority — otherwise it
 * silently removes nothing. See §5 for the measurement.
 *
 * WP-CLI is exempt: that is the path we are keeping.
 */

add_action(
	'init',
	static function () {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return;
		}

		remove_action(
			ActionScheduler_QueueRunner::WP_CRON_HOOK,
			array( ActionScheduler_QueueRunner::instance(), 'run' )
		);
	},
	100
);
```

Optional but recommended — the second tripwire, `wp-content/mu-plugins/cron-log-non-cli-queue-runs.php`. Step 2b's watches the cron endpoint; this one watches the queue itself, and the two catch different things:

```php
<?php
/**
 * Log any queue run that isn't WP-CLI. Blocks nothing.
 *
 * Action Scheduler fires this hook at the start of every run() call,
 * whatever triggered it. If the queue is ever processed outside CLI, a
 * line lands in the error log naming the SAPI, the URI and the caller.
 *
 * Request URI and remote address are attacker-controlled — this exists to
 * log hostile requests — so control characters are stripped before they
 * are written, or a crafted request could forge extra log lines.
 *
 * Note the blind spot in §4: one entry point never fires this hook.
 */

add_action(
	'action_scheduler_before_process_queue',
	static function () {
		if ( 'cli' === PHP_SAPI ) {
			return;
		}

		$clean = static function ( $value ) {
			return (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value );
		};

		error_log(
			sprintf(
				'[cron-guard] queue run outside CLI: sapi=%s uri=%s ip=%s',
				PHP_SAPI,
				isset( $_SERVER['REQUEST_URI'] ) ? $clean( $_SERVER['REQUEST_URI'] ) : 'n/a',
				isset( $_SERVER['REMOTE_ADDR'] ) ? $clean( $_SERVER['REMOTE_ADDR'] ) : 'n/a'
			)
		);
	}
);
```

## Step 5 — Schedule the runner

One entry, standing in for the loopback you just closed:

```cron
# Core's own scheduled events and the Action Scheduler queue, in one pass.
* * * * * cd /var/www/html && flock -n /tmp/wp-cron.lock wp cron event run --due-now --quiet
```

> **Action Scheduler also ships its own command, `wp action-scheduler run`**, which drains the same queue without going through the WP-Cron hook. It is not a substitute for the entry above: it drains the queue and nothing else, so a site scheduling only that command has closed the web loopback and left core's own events — version checks, privacy cleanups, site health — with nothing to fire them. Schedule it *alongside* `wp cron event run --due-now` if you want it, never in place of it. If you schedule both, each action's log records which command claimed it — some read `WP Cron`, some read `WP CLI`, and which one wins a given action is a race. Weighing that split is out of scope for this post, which keeps a single entry point so there is a single place to look. §6 covers what the labels mean.

---

# Part II — What each step is actually doing

## 1. `DISABLE_WP_CRON` is a scheduling switch, not a lock on the door

The constant's name invites a reasonable assumption: turn it on, and WP-Cron stops running on this site. What it actually does is narrower. It stops WordPress from spawning its own loopback request at the end of a page load — and that is all. In core's whole cron path the constant is read in exactly one place, inside the function that decides whether to spawn. Nothing in `wp-cron.php` consults it.

Core says so itself, in that file's own header: defining `DISABLE_WP_CRON` and calling the file directly *"are mutually exclusive and the latter does not rely on the former to work."*

So here is the whole of it. Set the constant to `true`, change nothing else, and run one command from anywhere on the internet:

```bash
curl -s https://example.com/wp-cron.php
```

That request drained **50 of 50** pending actions — and it ran core's own due events on the way, because that is the endpoint's entire job: it fires everything the schedule says is ready, and the queue drain is one entry on that list. No cookie, no nonce, no capability check, no authentication of any kind — the file is a public endpoint by design, because it has to be callable by whatever is meant to be calling it. The scheduling switch was on the whole time. Cron ran anyway, because reachability was never what the constant governed.

**Here is the one duration in this post, and it is here for a reason.** That request returned in **0.008 seconds** — eight milliseconds — and the fifty actions drained *afterwards*, in the same PHP worker, with the connection already closed. This is not a figure about how fast the queue is. It is the cheapest available proof that the response and the work are two separate things: your monitoring saw an 8 ms `200`, and fifty actions ran after the client hung up.

### The same property, read from the other side

Everything above is also a description of a request an attacker can send, and it is worth being explicit about why this endpoint is a favourite.

Reading core's own order of operations in that file: the response is flushed and the connection closed **first**; then WordPress boots in full via `wp-load.php`; then `wp_raise_memory_limit( 'cron' )` lifts that worker's memory ceiling; then, and only then, does core read the `doing_cron` transient to find out whether another cron run is already in progress and it should stand down.

Three consequences follow from that ordering, and none of them is a bug — each is a deliberate choice with a good reason behind it:

- **The attacker pays for a socket that is closed almost immediately.** Flooding a slow endpoint normally costs the attacker too: they have to hold connections open while the server works. Here the server hangs up first and keeps working. One cheap request buys a full WordPress bootstrap in a worker that the client is no longer waiting on.
- **The lock is checked after the bootstrap, not before it.** `WP_CRON_LOCK_TIMEOUT` defaults to sixty seconds, so the queue itself can only *drain* about once a minute no matter how hard the endpoint is hit. But that lock lives in the database and is read by WordPress — which means booting WordPress is what it costs to find out you were supposed to stand down. Rate-limited work, unlimited bootstraps.
- **Nothing in the request needs to be valid.** There is no signature to forge and no state to guess. The one thing core does reject is a request body: `wp-cron.php` calls `die()` if `$_POST` is non-empty, so this is a `GET`-only endpoint. That is the extent of the input validation.

None of this is exotic knowledge; it is the reason "block `wp-cron.php` and drive cron from the system scheduler" is standard advice at every managed WordPress host, and the reason serious hosts rate-limit or refuse this path at their edge rather than leaving it to each site. What the measurement adds is the part people skip: **`DISABLE_WP_CRON` does not participate in any of it.** A site that has moved cron to the system scheduler, and believes it has therefore turned the web path off, is running exactly the endpoint described above — with the additional property that the operator is not watching it, because they think it is disabled.

That is the argument for step 2, and the whole reason the tutorial has a step 2 at all. Why closing it inside WordPress is not enough on its own is the next section.

## 2. `wp-cron.php` answers 200 no matter what your guard decides

Core's `wp-cron.php` calls `fastcgi_finish_request()` **before it loads WordPress**. The order is not subtle:

```
line 19   ignore_user_abort( true )
line 21   if ( ! headers_sent() ) {
line 22-23    two cache headers — no status set, so PHP's implicit 200 is queued
line 26   // Don't run cron until the request finishes, if possible.
line 28   fastcgi_finish_request()   ←── response flushed and closed HERE
line 33   bail if this is a POST, AJAX, or already DOING_CRON
line 42   define( 'DOING_CRON', true )
line 46   require wp-load.php  ──> wp-config.php ──> mu-plugins load HERE
```

At the moment of the flush, WordPress does not exist in that process. `wp-config.php` hasn't been read, so `DISABLE_WP_CRON` isn't defined yet. No mu-plugin has loaded. **The response is gone before any code of yours can have an opinion about it** — which is why nothing you configure can prevent this.

By the time step 2b's `exit` runs, the client has been answered. And its `http_response_code( 403 )` doesn't merely fail to reach the client: measured against a live request, **PHP refuses the call outright.** It returns `false`, and reading the status back afterwards still reports **200**. The 403 does not exist anywhere.

**It does not fail quietly, though, and this is where the two halves of step 2b meet.** Called after the flush, `http_response_code()` writes a line into PHP's error log:

```
PHP Warning:  http_response_code(): Cannot set response code - headers already sent in .../cron-web-entry-point.php on line 143
```

One per blocked request, in the same file the guard's own log line goes to. That is the only trace the refusal leaves anywhere — and it is the wrong trace: a complaint about a failed function call, sitting next to the record of the request that caused it, saying nothing about who sent it or what was due. It looks like a bug in your mu-plugin, because in a sense it is one.

Which is why step 2b tests `headers_sent()` before making the call at all. The test isn't defensive habit; it keeps the log you are actually going to read free of a warning that fires on every single hit. Suppressing it with `@` would work too, and is worse: it hides a real fact about the platform instead of encoding it. Ask whether the headers are gone, and the answer tells you which server you're on — on Apache with mod_php they aren't, so the call proceeds and the client genuinely receives a 403.

Core does the same thing three lines into the file above. Look again at the timeline: line 21 is `if ( ! headers_sent() )`, wrapped around core's own two `header()` calls. `wp-cron.php` is written by people who knew this file might be reached with the response already gone. Your guard is in the same position, one bootstrap later.

Here is the same block, armed, at both layers, with four independently recorded statuses for one request:

| Where the block lives | Client read | Web server logged | PHP-FPM logged | PHP's own status at shutdown | Drained |
|---|---|---|---|---|---|
| **In WordPress** (mu-plugin) | 200 | 200 | 200 | 200 | **0 of 50** |
| **In front of PHP** (nginx) | 403 | 403 | never reached PHP | — | **0 of 50** |
| *No block at all*, for comparison | 200 | 200 | 200 | 200 | **50 of 50** |

Read the first and third rows together. **Every observable status is identical between a block that stopped everything and no block at all.** The guard worked — zero drained, zero probe executions logged — and nothing anywhere recorded a refusal. Not the client, not nginx, not PHP-FPM, not PHP itself.

This is a feature, not a bug, which is why it will not be fixed. Core's own comment, immediately above that block at line 26, reads *"Don't run cron until the request finishes, if possible."* The point of flushing early is that the unlucky visitor who triggered a loopback isn't kept waiting for someone else's scheduled work. Response masking is the side effect of that kindness.

## 3. Why the block goes in front of PHP — and what it costs

§2 is the whole argument for step 2a. A block inside WordPress is real but unverifiable from outside; a block in the web server is real *and* legible. It also never starts PHP, which means an endpoint you have decided is closed stops costing you a worker and a full WordPress bootstrap on every hit.

The cost is a genuine one, and it is measured rather than warned about. nginx cannot read a PHP constant, so it cannot make the block conditional. Run both blocks against a site where `DISABLE_WP_CRON` is `false` — a site that still depends on the loopback for cron to run at all:

| Block | `DISABLE_WP_CRON` | Client read | Drained |
|---|---|---|---|
| In WordPress (mu-plugin) | `false` | 200 | **50 of 50** — goes inert, cron keeps working |
| In front of PHP (nginx) | `false` | 403 | **0 of 50** — cron is gone |

The mu-plugin checks the constant and stands down. nginx blocks regardless, and that site's scheduled work stops silently — no error, no log line, just a queue that never moves.

**So: an observable status, or a conditional block that is safe to deploy everywhere. At one layer you can have either, not both.** For a single site you are configuring deliberately — which is what the tutorial above assumes — the nginx block is the better default, because you set the constant yourself in step 1 and the condition is already satisfied by hand. For a config rolled out across a fleet, the conditional check in step 2b is the one that won't take down the site that hadn't finished migrating. Installing both, as the tutorial does, is what makes that choice recoverable instead of load-bearing.

## 4. Action Scheduler has four entry points; closing one closes nothing

This is the one part of the arrangement that is specifically about Action Scheduler, and it is the reason steps 3 and 4 exist at all. Core's due events have effectively one door: the stored schedule, walked either by `wp-cron.php` or by WP-CLI. Close the endpoint and they stop. Action Scheduler adds three more doors of its own, and a block on the cron endpoint cannot see any of them.

`action_scheduler_run_queue` can be reached at least four independent ways. Each was exercised on its own, against the same 50-action queue:

- **`wp-cron.php` over HTTP.** 50 of 50, as in §1.
- **A direct POST to `admin-ajax.php?action=as_async_request_queue_runner`.** Unauthenticated, 50 of 50 drained. With the web-entry-point block, the async suppression, *and* the tripwire all armed: **still 50 of 50.** Suppressing dispatch stops WordPress from *sending* that request. It does nothing to gate the endpoint against someone sending an equivalent one.
- **An authenticated admin page load.** Action Scheduler's own dispatcher reaches its decision on `shutdown` and fires the loopback: 50 of 50. With the suppression armed, **0 of 50** — and the record shows the decision point was genuinely reached and answered no, rather than never consulted at all.
- **The "Run" row action** on the Scheduled Actions admin screen. With **all four** guards armed, including the unhook: **1 of 50** — one action ran and completed.

Four doors. Each guard closes some of them. Only the unhook closes the hook itself — and the row action still works, because it never goes near the hook.

### The fourth door is meant to stay open

That last result is the one most likely to be misread, so it is worth being exact about what it is.

**One is the ceiling, not a leak.** The row-action handler takes a single action ID from the request and processes that one action. One click, one action. So `1 of 50` is the whole of what that path can do per click — not a guard that let one action slip past while catching forty-nine.

**It is nothing like the door in §1.** The handler requires `row_action`, `row_id` and `nonce` to all be present, verifies the nonce against that specific action ID, and the screen it lives on is registered under `manage_options`. That is an authenticated administrator, on a nonce-checked request, running one action they picked by hand. Compare the unauthenticated `GET` in §1 that drained the entire queue: same queue, entirely different threat model.

**Nothing in this arrangement is trying to close it**, and that is deliberate. When a job has failed and you want to retry exactly one action while watching what happens, this is the tool. Keeping it is a feature, not a gap left behind.

The reason it survives all four guards is structural rather than lucky. The row action calls the queue runner's `process_action()` directly, so: the web-entry-point block never fires, because an admin page load doesn't define `DOING_CRON`; the async suppression has no dispatch to suppress; and the unhook removes a callback from a hook this path doesn't use. Short of adding a fifth guard aimed specifically at it, it stays reachable — which is the intent.

**The one thing it does cost you is a detection gap, and a dashboard can hide it.** On that same measurement the queue tripwire from step 4 reports no line at all, while an action genuinely ran. `action_scheduler_before_process_queue` is fired in exactly two places in Action Scheduler: the queue runner's `run()` and the WP-CLI runner. `process_action()` fires neither. The entry-point tripwire from step 2b says nothing either, and correctly so — this door isn't the cron endpoint.

**An empty tripwire log is not evidence that nothing ran.** The pending count is — and so is Action Scheduler's own execution context, which stamped this run `Admin List Table` and recorded it as started and completed. The blind spot is in the tripwire's wiring, not in Action Scheduler's records, which is why §6 argues for reading the context label instead.

## 5. The silent removal-timing trap

`remove_action()` removes a callback only if it runs *after* the matching `add_action()` has already executed, at the same priority. Run it earlier, or at a different priority, and it removes nothing — with no warning, no error, and no visible difference until the thing you thought you'd disabled fires anyway.

Checking `has_action()` at six points inside a single real HTTP request shows exactly where the margin is:

| Stage | `has_action( 'action_scheduler_run_queue' )` |
|---|---|
| `plugins_loaded:10` | false |
| `init:1` | false |
| `action_scheduler_init:10` | attached, priority 10 |
| `init:99` | attached, priority 10 |
| `init:101` | **false** (step 4 armed) / attached, priority 10 (not armed) |
| `wp_loaded:10` | **false** (step 4 armed) / attached, priority 10 (not armed) |

Action Scheduler hasn't attached the callback yet at `plugins_loaded:10` or `init:1` — an unhook attempt at either point is a guaranteed silent no-op, and both are plausible places to put one. It has attached by the time its own `action_scheduler_init` fires, still inside `init` priority 1. Step 4 runs at `init:100`; by `init:101` the callback reads `false`, genuinely removed rather than merely untested.

## 6. Step 4 is what keeps step 5 working — and it is one line away from not

This is the load-bearing joint of the whole arrangement, and it is easy to miss because it looks like a detail.

`wp cron event run --due-now` does not know Action Scheduler exists. It walks core's list of due events, and one of those events is `action_scheduler_run_queue`. Firing it runs whatever is attached to that hook — which, before step 4, is Action Scheduler's queue runner. That is the entire mechanism: the command reaches the queue through the same hook `wp-cron.php` would have used, and it drained **50 of 50**.

Step 4 then removes that callback. For every non-CLI request, `action_scheduler_run_queue` becomes a hook with nothing on it. **The `if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }` at the top of that guard is the only reason step 5's command still drains anything.** Delete those two lines, or tighten the exemption to a narrower condition than you meant, and `wp cron event run --due-now` keeps working perfectly: it still runs core's scheduled events, still exits zero, still prints success for every event it fired — while running not one Action Scheduler action. Nothing warns you. The pending count simply stops falling.

That failure mode is why the thing to verify is the pending count, not the command's output. A cron entry that reports success and drains nothing is the same shape of problem as a guard that returns 200 and blocks everything: the signal you'd naturally trust has come apart from the thing you care about.

**What you get for accepting that fragility** is one hook with one purpose. `action_scheduler_run_queue` stops being a shared entry point that four callers can reach and becomes something exactly one caller uses. A guard aimed at "the WP-Cron hook" now has a single, well-understood meaning — and Action Scheduler's own logs will tell you when that stops being true. Open any action on the Scheduled Actions screen and its log names the trigger, one of four labels:

| Label | What it means after this arrangement |
|---|---|
| **`WP Cron`** | Expected. Your scheduled `wp cron event run --due-now`, which fires the WP-Cron hook. |
| **`Admin List Table`** | Expected, when someone clicked "Run" on a row. A person, not a schedule — see §4. |
| **`Async Request`** | **Investigate.** The async dispatch is suppressed, so this label should not appear. |
| **`WP CLI`** | Investigate, unless you also scheduled `wp action-scheduler run` on purpose. |

The point of the arrangement is that this column stops being noise. Before it, `WP Cron` could mean your scheduler, a stranger's `curl`, or a visitor who happened to trigger a loopback, and there was no way to tell them apart. After it, each label maps to one identifiable cause, and two of the four are things you should go and look at.

Note that `Admin List Table` is not an alarm. That path is deliberately left reachable, it is capability- and nonce-gated, and it runs exactly one action per click — §4 has the detail. What the label buys you is knowing a human did it, rather than wondering why the count moved.

**For queue activity specifically, this is a better monitor than the tripwire from step 4**, for two reasons: Action Scheduler records it for you, with none of your code involved, and it has no blind spot at the manual-run path. The "Run" button that never fires the tripwire's hook still gets stamped `Admin List Table` and recorded as started and completed.

It does not replace step 2b's entry-point log, though, and the difference is worth keeping straight. The context label answers *how did this action get run*. The entry-point log answers *is anyone still knocking on the cron endpoint* — including all the times they knocked and nothing was due, which the context label can never show you because no action ran to be labelled.

> **On Action Scheduler's own command.** `wp action-scheduler run` bypasses the WP-Cron hook entirely, so it is immune to the fragility above — and it takes `--batch-size`, `--batches`, `--group` and `--hooks`, which lets a slow group get its own schedule and its own lock. Its actions log as **`WP CLI`**. Bypassing the hook is also its limitation: core's own due events live on that hook list and this command never touches them, so it is an addition to step 5's entry rather than a replacement for it. Running both is legitimate and the queue's claim mechanism keeps them from processing the same action twice, but you then read two labels for work you consider identical. When to prefer it is a separate question from this post's, which is about which doors reach your scheduled work.

## 7. Six ways to fool yourself while testing this

A result of "0 of 50 drained" looks identical whether your block worked or your test was broken. Before believing any measurement here, rule these out — each one produces a convincing false pass on its own:

1. **Empty queue.** Nothing was pending. Check the count *before* the request, not only after.
2. **No callback attached to the action.** Action Scheduler completes actions as instant no-ops when nothing is hooked to them. The pending count still drops and nothing logs — which reads exactly like a successful drain.
3. **A stale claim row** in `wp_actionscheduler_claims`, left by a killed process. It blocks the runner from claiming anything, queue or no queue.
4. **A leftover `doing_cron` transient.** WordPress core's own cron lock. A stale copy makes both `wp-cron.php` and `wp cron event run --due-now` into silent no-ops. `wp transient delete doing_cron` clears it.
5. **Code that isn't the code you think you're testing.** Opcache holding a previous version of a mu-plugin, a container built from a stale image, a deploy that didn't reach the box you're curling. Read the versions back from the running site rather than from your lockfile.
6. **An empty entry-point log, read as a broken tripwire.** With step 2a armed, nginx refuses the request and PHP never starts — so step 2b's log line is *supposed* to be absent. That is the arrangement working exactly as designed, and it is indistinguishable from a logger you wired up wrong. To test the log itself, take the web-server block out of the way first; to test the whole arrangement, put it back. Confusing the two costs an afternoon.

And the seventh, which is the reason this post exists: **judging a block by the HTTP status it returns.** On the armed cron entry point, all four independently recorded statuses read 200 — client, web server, process manager, and PHP's own status at shutdown — the same four values the unblocked run produced while draining all fifty actions. Status and outcome are two different facts. Only one of them tells you whether your queue is safe.

## 8. The whole arrangement, and what it costs

Put together: trigger cron from a system scheduler through WP-CLI's cron command, which carries core's own events and the Action Scheduler queue in one pass; close `wp-cron.php` in the web server, where the refusal is observable and PHP never starts; log every hit that reaches PHP anyway and *then* close it again inside WordPress, conditionally, so the file is safe to deploy anywhere and no refusal goes unrecorded; suppress Action Scheduler's outbound async dispatch, knowing it closes the door WordPress opens and not the endpoint itself; unhook the queue runner outside WP-CLI at a priority verified to run after Action Scheduler attaches; keep a second tripwire on non-CLI queue runs, with its manual-run blind spot known rather than assumed away; and leave the admin "Run" button alone, because a person retrying one action by hand is not the problem you set out to solve.

The result is the same hook doing the same work as before, reached from a process you scheduled instead of a request someone else triggered.

What it costs you, stated plainly:

- **No web fallback.** If the system scheduler stops, the queue stops. Monitor the scheduler, or monitor the pending count — something has to watch it now that nothing will accidentally cover for you.
- **One exemption holding up the whole path.** Step 5 drains the queue only because step 4 stands down under WP-CLI. Anyone tightening that guard later will get a cron entry that reports success and runs zero actions.
- **One door deliberately left open.** The manual "Run" button still works — one action per click, behind a capability check and a nonce — and this arrangement makes no attempt to close it. The cost isn't the door, it's that neither tripwire can see through it, which is why the pending count and the execution-context label, not a log line of your own, are the things to watch.
- **A log that grows in proportion to the noise.** The entry-point tripwire writes a line for every hit on `wp-cron.php` that reaches PHP. Behind the nginx block that should be nothing at all; without it, a busy endpoint can produce real volume. That is information rather than a nuisance — but rotate the log, and if the volume is high, the answer is step 2a rather than deleting the line.
- **A fleet decision you cannot dodge.** The nginx block is unconditional. Either deploy it per-site, or rely on the conditional in-WordPress block for the sites that haven't migrated yet.

One thing this post deliberately does not tell you: whether any of it is worth doing for your site. That depends on what a drain costs you, which is a measurement about your workload and your worker pool — and, as promised at the top, not a number anyone should borrow from someone else's stack.
