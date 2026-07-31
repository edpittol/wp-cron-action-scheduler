# WP-Cron and Action Scheduler: what closing one door actually closes

*Draft. Every number below is read from a file committed to [`edpittol/wp-cron-action-scheduler`](https://github.com/edpittol/wp-cron-action-scheduler): a JSON result record under `results/`, or a rendered figure in `docs/measurements/full-scenario-matrix-report.md` or `docs/measurements/unhook-timing-4.0.0.md`. Each claim below names the command that produced it and the file it can be re-read from. Two findings from the original ten-section investigation this project reproduces are left out entirely, because this stack cannot demonstrate them — see the project [`README`](../README.md#findings-this-stack-cannot-demonstrate) for exactly which two and why. Nothing in this post rests on either of them.*

## 1. `DISABLE_WP_CRON` is a scheduling switch, not a lock on the door

The constant's name invites a reasonable-sounding assumption: turn it on, and WP-Cron stops running on this site. What it actually does is narrower — it stops WordPress from calling `spawn_cron()` at the end of an ordinary page load. It says nothing about `wp-cron.php` itself, which stays exactly where it always was: a plain, publicly reachable PHP script, servable to anyone who requests it, constant or no constant.

One command demolishes the assumption in a single shot. With `DISABLE_WP_CRON` set to `true` and no guard in place, an unauthenticated `GET` against `wp-cron.php` still drains an entire pending queue:

```
bin/stack measure-http http-cron-unarmed
```

Result: 50 of 50 seeded probe actions drained, `fully_drained: true`, HTTP 200, in 18.620 seconds, with `DISABLE_WP_CRON` reported as `true` for the whole run (`docs/measurements/full-scenario-matrix-report.md`, `http-cron-unarmed` row). The "scheduling switch" was on. The queue ran anyway, because nothing about `wp-cron.php`'s reachability depends on it. Whatever access control a site has around WP-Cron, it isn't this constant.

## 2. Action Scheduler has four entry points; closing one closes nothing

The queue-runner hook, `action_scheduler_run_queue`, can be reached at least four independent ways, and each was exercised on its own:

- **`wp-cron.php` over HTTP.** Already shown above: 50/50 drained (`http-cron-unarmed`).
- **Action Scheduler's own async loopback**, a POST to `admin-ajax.php?action=as_async_request_queue_runner`. Unarmed, this drains 50/50 in 23.124 seconds, HTTP 200 (`bin/stack measure-async-ajax async-ajax-unarmed`; `docs/measurements/full-scenario-matrix-report.md`, `async-ajax:async-ajax-unarmed` row).
- **The same POST, with guard sections 1–3 armed** — `wp-cron.php` blocked, async dispatch's *outbound* send suppressed, and a non-CLI canary logging — still drains 50/50 in 23.966 seconds, HTTP 200 (`bin/stack measure-async-ajax async-ajax-sections-1-2-3-armed`; `docs/measurements/full-scenario-matrix-report.md`, `async-ajax:async-ajax-sections-1-2-3-armed` row; the raw record's `outcome.drained` is 50, `outcome.fully_drained` is `true`). Suppressing *dispatch* only stops Action Scheduler from *sending* that request itself; it does not gate the endpoint against someone sending an equivalent request directly.
- **A manual "Run" click** on the Scheduled Actions admin screen, with *all four* guard sections armed — including the unhook. This still drains one pending action, HTTP 200, in 0.318 seconds (`bin/stack measure-manual-run manual-run-sections-1-2-3-4-armed`; `results/matrix/20260729T174806Z/20260729T175311Z-manual-run-manual-run-sections-1-2-3-4-armed.json`, `outcome.drained: 1`).

That last result also exposes a detection gap worth stating plainly, because it's the kind of thing a dashboard can hide. The same manual-run record shows `canary_fired: false` and `canary_line: null`, even though an action genuinely ran. The record explains why, in its own `vector.canary_blind_spot` field: the admin list table's "Run" action calls `ActionScheduler_QueueRunner::process_action()` directly, and never calls `run()` or fires `action_scheduler_before_process_queue` — the exact hook the section-3 canary listens on. **A null canary line here is not evidence nothing ran; `outcome.drained` is.** Anyone monitoring only the canary log for non-CLI queue activity has a blind spot at this one entry point, by design of where the canary is wired, not by a bug in it.

Four doors, one lock changed at a time: none of the individual guard sections, alone or in the three-section combination above, stops all four.

## 3. The silent removal-timing trap

`remove_action()` only removes a callback if it runs *after* the matching `add_action()` has already executed, at the same priority. Run it earlier, or at a different priority, and it removes nothing — with no warning, no error, and no visible difference in behavior until the thing you thought you'd disabled fires anyway.

`bin/measure-unhook-timing` checks `has_action( WP_CRON_HOOK )` at six points across a single request, purpose-built to show exactly where this margin is:

| Stage | `has_action(WP_CRON_HOOK)` |
|---|---|
| `plugins_loaded:10` | false |
| `init:1` | false |
| `action_scheduler_init:10` | attached, priority 10 |
| `init:99` | attached, priority 10 |
| `init:101` | false |
| `wp_loaded:10` | attached, priority 10 (guard disarmed) / false (guard armed) |

(`docs/measurements/unhook-timing-4.0.0.md`, "Result: Action Scheduler 4.0.0" table, mirrored in `docs/measurements/full-scenario-matrix-report.md` under "Unhook timing table (issue #8)".) Action Scheduler hasn't attached the callback yet at `plugins_loaded:10` or `init:1` — an unhook attempt at either point is a guaranteed silent no-op. It has attached by `init:99`. The guard used in this stack runs at `init:100`; by `init:101`, the callback reads `false` — genuinely removed, not just untested.

This margin is not new in Action Scheduler 4.0.0, and the measurement confirms it rather than assuming it: the same document diffs `action-scheduler.php`, `ActionScheduler.php::init()`, and `ActionScheduler_QueueRunner.php::init()` directly against the 3.9.0 and 4.0.0 tags and finds the attachment point unchanged — hook `init`, priority 1, still calling `add_action( self::WP_CRON_HOOK, ... )` at the default priority 10 in both versions (`docs/measurements/unhook-timing-4.0.0.md`, "Where 4.0.0 attaches the callback"). A guard timed correctly against 3.9.0 needed no retiming for 4.0.0.

## 4. The CLI path is a genuinely different code path, not just a different door

Two WP-CLI controls both fully drain a 50-action queue, and both are correctly exempt from the HTTP-only and non-CLI guard sections — but they get there by different mechanisms, and the result records show it in the execution-context labels each one leaves behind:

- `wp cron event run --due-now` fires through the same `WP_CRON_HOOK` that `wp-cron.php` uses. Its record shows `execution_contexts.started` as `{"WP Cron": 50}`, 50/50 drained in 18.462 seconds (`bin/stack measure wp-cron`; `results/matrix/20260729T174806Z/20260729T174807Z-wp-cron.json`).
- `wp action-scheduler run` uses `ActionScheduler_WPCLI_QueueRunner` and never touches `WP_CRON_HOOK` at all. Its record shows `execution_contexts.started` as `{"WP CLI": 50}` instead, 50/50 drained in 17.861 seconds (`bin/stack measure action-scheduler`; `results/matrix/20260729T174806Z/20260729T174837Z-action-scheduler.json`).

Both succeed regardless of arming, because guard section 1 only inspects the HTTP-only `wp-cron.php` request path, and section 4's unhook guard explicitly stands down for WP-CLI (`if ( defined('WP_CLI') && WP_CLI ) { return; }`). But "the CLI path is exempt" is not one fact, it's two: one CLI command reaches the queue through the hook the guards reason about, the other bypasses that hook entirely. Log correlation, or any future guard aimed at "the WP-Cron hook," needs to know which CLI command it's looking at.

## 5. Removing the web fallback is usually a phantom cost

Guard section 1 doesn't block `wp-cron.php` unconditionally — it's gated on `DISABLE_WP_CRON` being `true`, and that condition is exactly what makes "losing the fallback" mostly not a real cost.

With `DISABLE_WP_CRON` true and section 1 armed, `wp-cron.php` returns 403 in 0.005 seconds and drains 0 of 50 (`bin/stack measure-http http-cron-armed`; `docs/measurements/full-scenario-matrix-report.md`, `http-cron-armed` row) — a 403 that's real on this stack's PHP built-in server specifically; under PHP-FPM the same guard's `exit` would still block the queue, but the client would see a masked 200 instead, per the README's documented deviations, which is exactly why §8 below treats `drained: 0`, not the status code, as the load-bearing figure. At that point the web path was already contributing nothing — closing it doesn't remove a safety net that was catching cron runs, it retires a path that was already dead weight once `DISABLE_WP_CRON` had been set.

The other side of that same condition is the actual fallback case, and it's handled automatically rather than needing to be remembered: flip `DISABLE_WP_CRON` back to `false` with the same guard section still armed, and `wp-cron.php` drains 50/50 again, in 17.928 seconds, HTTP 200 (`bin/stack set-disable-wp-cron false` then `bin/stack measure-http http-cron-armed-cron-enabled`; `docs/measurements/full-scenario-matrix-report.md`, `http-cron-armed-cron-enabled` row). A site that still genuinely relies on the web loopback keeps it; a site that has already turned it off loses nothing by having the door locked as well as unused.

## 6. Capacity, measured on this stack's server — not on PHP-FPM

This project's stack runs PHP's built-in server, not PHP-FPM, with `PHP_CLI_SERVER_WORKERS=6` (`docker/Dockerfile`). Every figure in this section describes that server model specifically; none of it should be read across to a PHP-FPM worker pool, which is sized and scheduled differently.

`bin/stack occupancy` seeds a due-now batch, triggers the drain, and fires waves of concurrent front-end requests at concurrency 3, 6, 12, and 24 while polling the pending count out-of-band over WP-CLI. On the committed run:

- Server model: `php-cli-server, 6 workers`. Workers occupied by the drain: **1** (`results/matrix/20260729T174806Z/occupancy-drain.json`, `workers_occupied_by_drain`). That figure is explicitly not a direct counter read — the record's own `workers_occupied_by_drain_basis` field states it's inferred from the built-in server's one-worker-per-request model together with the continuous, gap-free pending-count decline actually observed in this run, not a server-reported occupancy metric.
- Drain duration: **12.328 seconds**, `drain_completed: true`, `drain_observed_in_flight: true` (same file, `drain_duration_seconds`).
- Front-end latency during that drain, against a same-shape baseline measured before the drain started (`docs/measurements/full-scenario-matrix-report.md`, "Worker occupancy during a drain" table):

  | Concurrency | Baseline avg (s) | During-drain avg (s) | Delta |
  |---|---|---|---|
  | 3 | 0.0450 | 0.0346 | -23.1% |
  | 6 | 0.0475 | 0.0458 | -3.6% |
  | 12 | 0.0922 | 0.0801 | -13.2% |
  | 24 | 0.1303 | 0.1367 | +4.9% |

  `degradation_observed` for this run is `false` — no concurrency level crossed the record's own >25% threshold, and three of the four deltas are negative (front-end requests measured faster, not slower, during the drain; almost certainly noise, not a real speedup).

On this server, one 50-action drain occupying one of six workers for roughly 12 seconds did not produce a measurable front-end latency cost at any tested concurrency. That is a statement about a 6-worker built-in-server pool with this workload's shape — it is not a claim about PHP-FPM capacity planning, and this post doesn't make one.

## 7. The recommended architecture, including the shared-`mu-plugins` caveat

Put together, the four sections' measured behavior points at one arrangement: trigger the queue from a system scheduler through WP-CLI (either control shown in §4 fully drains, independent of arming), block `wp-cron.php`'s HTTP path (§1, §5 — real and, per this stack's own guard, self-limiting to exactly the case where it's dead weight), suppress Action Scheduler's own outbound async dispatch (§2 — necessary but not sufficient on its own, since the direct-POST path stays open unless something else gates it), keep the non-CLI canary for detection (§2 — with its manual-run blind spot known and monitored around, not assumed away), and keep the unhook armed outside WP-CLI at a priority verified to run after Action Scheduler attaches (§3 — confirmed unchanged on Action Scheduler 4.0.0).

The caveat that matters most for a multi-site setup: guard section 1's block is conditional on `DISABLE_WP_CRON`, not unconditional (§5's fleet-caveat evidence is the direct proof — the same guard file drains 50/50 the moment `DISABLE_WP_CRON` goes back to `false`). If `mu-plugins/` is shared across a fleet rather than deployed per-site, that conditional check is what keeps a site that still genuinely depends on the web loopback from silently losing cron the moment the shared guard file lands — the block only activates for sites that have already opted into `DISABLE_WP_CRON`. A guard authored without that check, deployed fleet-wide, would 403 `wp-cron.php` everywhere, including on the one site that hadn't turned the constant on yet.

## 8. Methodology: the trap list, and why every scenario carries a preflight snapshot

A "0 of 50 drained" result looks identical whether a guard worked or the test was broken. This project's own harness treats that ambiguity as the central risk, not an afterthought, and the committed records show the check, not just the claim.

Four traps recur, each capable of producing a convincing false result on their own:

- **Empty queue** — nothing was pending to drain in the first place.
- **No callback attached** — Action Scheduler completes actions as instant no-ops when nothing is hooked to the probe action; the count still drops, and nothing logs.
- **A stale claim row** left in `wp_actionscheduler_claims` — blocks `run()` from claiming anything, queue or no queue.
- **A leftover `doing_cron` transient** — makes both `wp-cron.php` and `wp cron event run --due-now` silent no-ops.

`bin/stack preflight` asserts against exactly these four before trusting any measurement, and every `measure*` subcommand runs that same check again internally before it will record a result (`README.md`, verb reference for `bin/stack`). The effect is visible in every committed record: each one's own `preflight` field is a snapshot taken immediately before the control ran. The `http-cron-armed` record, for instance, reads `ok: true, pending_count: 50, callback_attached: true, cron_in_progress: false, claims_count: 0` right before returning its 0/50, 403 result (`results/matrix/20260729T174806Z/20260729T174936Z-http-cron-armed.json`, `preflight`; the same four fields appear as the "Preflight snapshot" column for every row of `docs/measurements/full-scenario-matrix-report.md`) — so that specific zero is traceable to the guard, not to one of the four traps above.

The harness treats a failed check the same way it treats a real block: `bin/measure-matrix`'s own description states that a preflight or measurement failure is recorded as an explicit negative result, never silently dropped or retried into a different answer (`README.md`, `bin/measure-matrix` section) — and the committed run backing every figure above reports **zero** negative results across its eleven scenarios (`docs/measurements/full-scenario-matrix-report.md`, header line: "11 scenario record(s), 0 negative result(s)"). One trap worth naming even though this stack's environment doesn't reproduce it the way the original investigation did: judging a guard purely by the HTTP status it returns. The `http-cron-armed` scenario above did return an observable 403 on this stack's server — but the result this post actually cites for that scenario is `outcome.drained: 0` and `probe_executions_observed: 0`, not the status code, precisely because status and outcome are two different fields for a reason.

---

*This draft accompanies the `wp-cron-action-scheduler` PoC repository. Every scenario referenced above can be reproduced from a clean checkout with `bin/stack up`, `bin/measure-matrix`, and `bin/generate-report`; see the project [`README`](../README.md) for the full command reference and the documented deviations between this stack and the environment the original investigation measured.*
