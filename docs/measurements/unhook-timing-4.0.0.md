# Unhook timing table, measured on Action Scheduler 4.0.0

Issue: #8. Blocked by, and measured on top of, #3 (the four guard files and `bin/guard arm`).

## What this measures

Whether `has_action( ActionScheduler_QueueRunner::WP_CRON_HOOK )` (the hook WP-Cron fires, the async loopback dispatches to, and a direct call from outside WordPress can trigger too) is attached at six points inside a single real web request:

1. `plugins_loaded:10`
2. `init:1` (early init)
3. `action_scheduler_init:10` (Action Scheduler's own "I am ready" hook)
4. `init:99` (immediately before the unhook guard's `init` priority, 100)
5. `init:101` (immediately after that same priority)
6. `wp_loaded:10`

`remove_action()` only succeeds if it runs after the matching `add_action()` has already executed; running earlier, or targeting a different priority, removes nothing and fails silently. This table exists to show precisely which of the six windows above see the callback attached and which don't -- i.e., where an unhook guard would actually do something versus where it would silently do nothing.

## Method

Stack: `bin/stack up` (WordPress 7.0.2 + Action Scheduler 4.0.0, both pinned in `docker/composer.lock`, on nginx + PHP-FPM + SQLite). Originally measured under PHP's built-in server, which this repo no longer contains (ADR-0001); re-measured under nginx + PHP-FPM and every value below is unchanged, as expected -- when a callback is attached during a request is a function of WordPress's own hook order, not of the server in front of it.

Probe: `docker/mu-plugins-available/90-unhook-timing-probe.php`. Not a guard -- not one of `bin/guard`'s five numbered sections. It registers a callback at each of the six stages above; each callback calls `has_action()` for the queue runner's callback and records the result. The stage-6 read runs on `wp_loaded` at priority 10, matching its own label; a *separate* `wp_loaded` priority-20 callback (after the stage-6 read) emits the collected results as a JSON response body and exits, so a single `curl` per request captures the full set. (Earlier drafts of this probe read and emitted from the same priority-20 callback, which made the `wp_loaded:10` label misstate its own priority; splitting the read from the emit fixed that without changing any measured value.) It only activates on `?wpcas_probe=1`; every other request is untouched. It has zero effect unless copied into `docker/mu-plugins/`, which only `bin/measure-unhook-timing` does, and only for the duration of the measurement.

Driver: `bin/measure-unhook-timing`. Two requests, per issue #8's "the measurement does not depend on arming beyond what it needs to observe" acceptance criterion:

- **Request 1 -- every guard section disarmed.** Reports the five stages that are not about removal: `plugins_loaded:10`, `init:1`, `action_scheduler_init:10`, `init:99`, `wp_loaded:10`. These are observed with nothing else interfering with the queue runner's registration.
- **Request 2 -- only section 4 (the unhook guard, `40-unhook-queue-runner.php`) armed.** Reports `init:101`, the one row this measurement actually needs the guard armed to observe.

The guard is disarmed again, and the probe file removed from `docker/mu-plugins/`, once both requests complete (see the `trap cleanup EXIT` in the script).

## Result: Action Scheduler 4.0.0

Action Scheduler version measured (read back from `ActionScheduler_Versions::instance()->latest_version()` inside the same request): **4.0.0**.

| Stage | `has_action(WP_CRON_HOOK)` |
|---|---|
| `plugins_loaded:10` | false |
| `init:1` | false |
| `action_scheduler_init:10` | attached, priority 10 |
| `init:99` | attached, priority 10 |
| `init:101` | false |
| `wp_loaded:10` | attached, priority 10 |

Raw JSON, request 1 (guard disarmed):

```json
{
    "plugins_loaded:10": "false",
    "init:1": "false",
    "action_scheduler_init:10": "attached, priority 10",
    "init:99": "attached, priority 10",
    "init:101": "attached, priority 10",
    "wp_loaded:10": "attached, priority 10",
    "action_scheduler_version": "4.0.0"
}
```

Raw JSON, request 2 (section 4 armed):

```json
{
    "plugins_loaded:10": "false",
    "init:1": "false",
    "action_scheduler_init:10": "attached, priority 10",
    "init:99": "attached, priority 10",
    "init:101": "false",
    "wp_loaded:10": "false",
    "action_scheduler_version": "4.0.0"
}
```

(Only `init:101` from request 2 is used in the table above; `wp_loaded:10` in the table comes from request 1, per the protocol described above. Both raw responses are kept here because the divergence between them at `wp_loaded:10` is itself the headline finding below.)

## Comparison against the 3.9.0 reference table

Reference table (prior work, Action Scheduler 3.9.0):

| Stage | `has_action(WP_CRON_HOOK)` |
|---|---|
| `plugins_loaded:10` | false |
| `init:1` | false |
| `action_scheduler_init:10` | attached, priority 10 |
| `init:99` | attached |
| `init:101` | false (removed at `init:100`) |
| `wp_loaded:10` | false |

Five of six rows match exactly: `plugins_loaded:10`, `init:1`, `action_scheduler_init:10`, `init:99`, and `init:101` are identical between 3.9.0 and 4.0.0, both in value and in the underlying mechanism (confirmed by reading Action Scheduler's source at both tags -- see "Where 4.0.0 attaches the callback" below). **No internal migration occurred here despite 4.0.0 being a major release**: the queue runner is still registered from `ActionScheduler_QueueRunner::init()`, still called from an `init` priority-1 callback wired up inside `ActionScheduler::init()`, and the `WP_CRON_HOOK` callback is still added with `add_action()`'s default priority (10). That "nothing moved" result is itself worth stating plainly for the post, since the ticket's premise was that 4.0.0 was the likeliest place for this to have changed.

**The sixth row, `wp_loaded:10`, differs: 4.0.0 shows `attached, priority 10` here, not `false`.** This is not evidence of a behavioral change in Action Scheduler. It is a measurement-protocol difference:

- This measurement's `wp_loaded:10` value comes from **request 1, guard disarmed** -- per issue #8's instruction that the un-removed rows be "observed WITHOUT the unhook guard interfering." With nothing removing the callback anywhere in that request, it is unsurprising that it is still attached by the time `wp_loaded` fires.
- The inherited 3.9.0 table's `false` at `wp_loaded:10` is consistent with having been produced in a **single request with the unhook guard armed for the whole thing** (its own footnote attributes the `init:101` `false` to the guard having run at `init:100`, but is silent on `wp_loaded`). If the guard is armed and removes the callback at `init:100`, that removal persists for the rest of the same request -- `wp_loaded` fires after `init` completes, so it would see the callback already gone, not because it was never attached, but as a residual effect of the earlier removal.
- **This measurement can confirm that residual-effect explanation directly**: request 2 above (guard armed) also recorded `wp_loaded:10: "false"` -- the exact same value the 3.9.0 table reports, under the exact protocol (guard armed for the whole request) that would produce it. The 4.0.0 behavior is fully consistent with the 3.9.0 behavior; the two tables' `wp_loaded:10` cells differ only because they were populated under two different protocols, not because Action Scheduler 4.0.0 attaches or drops the callback differently at that stage.

**Finding for the post:** the `wp_loaded` row's value is protocol-dependent, not version-dependent -- it tells you "was the guard armed for this request," not "does Action Scheduler still hold the callback by `wp_loaded`." A table that reports a single value per stage without stating which requests contributed which rows understates how easy it is to misread this one specific row.

## Where 4.0.0 attaches the callback (verified against source, both tags)

`https://raw.githubusercontent.com/woocommerce/action-scheduler/4.0.0/...` and the `3.9.0` equivalents were fetched and diffed directly (not inferred) to answer the ticket's explicit caveat about 4.0.0 having possibly moved this:

- `action-scheduler.php` (4.0.0) registers `ActionScheduler::init()` via a version broker (`ActionScheduler_Versions`), triggered from `plugins_loaded` priorities 0 and 1. This versioning wrapper already existed before 4.0.0 and is unrelated to the queue-runner attachment point.
- `classes/abstracts/ActionScheduler.php::init()` -- identical in both tags at the relevant lines: when `! did_action( 'init' )`, it calls `add_action( 'init', array( $runner, 'init' ), 1, 0 )` (among sibling `init`-priority-1 registrations for the store, logger, and -- new in 4.0.0 only -- a recurring-action scheduler; that addition doesn't change the runner's own priority or the callback it hooks).
- `classes/ActionScheduler_QueueRunner.php::init()` -- identical in both tags: `add_action( self::WP_CRON_HOOK, array( self::instance(), 'run' ) )`, i.e. still the hook `action_scheduler_run_queue`, still the default priority (10), no explicit priority argument in either version.

Net: the attachment point (hook `init`, priority 1, method `ActionScheduler_QueueRunner::init()`, called via `ActionScheduler::init()`) and the queue-runner's own hook (`action_scheduler_run_queue`, priority 10) are unchanged between 3.9.0 and 4.0.0. The unhook guard's `init:100` timing margin (see `docker/mu-plugins-available/40-unhook-queue-runner.php`) remains valid on 4.0.0 for the same reason it was valid on 3.9.0.

## Reproducing this measurement

```
bin/stack up
bin/measure-unhook-timing
```

`bin/measure-unhook-timing` prints both raw JSON responses and the derived table to stdout, and leaves the stack in a fully-disarmed, probe-free state afterward.
