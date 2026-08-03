# WP-Cron + Action Scheduler cron-runner-policy PoC

A bootable, single-command WordPress + Action Scheduler stack that reproduces the measured findings behind a `Cron Runner Policy` mu-plugin: what happens when you block `wp-cron.php` over HTTP, suppress Action Scheduler's async loopback, canary any non-CLI queue run, and unhook the queue runner outside WP-CLI. Every scenario in this repo writes a JSON result record to disk, so every number quoted anywhere in this repo (or the post it accompanies) traces back to a committed file, not a screenshot or a memory of a test run.

This repo is the isolated PoC referenced by `handoff-wp-cron-as-post-and-poc.md` at the repo root — read that file for the original findings, the ten-section post outline they support, and why an isolated, from-scratch reproduction was needed instead of reusing the original (non-public) environment.

## Purpose

Four guard sections live in `docker/mu-plugins-available/` and are individually toggled by `bin/guard`:

1. **Block HTTP requests to the cron entry point** — a mu-plugin that 403s any request that looks like `wp-cron.php` running outside CLI, while `DISABLE_WP_CRON` is true.
2. **Suppress Action Scheduler's async dispatch** — forces `action_scheduler_allow_async_request_runner` to `false`, stopping Action Scheduler's own loopback POST to `admin-ajax.php` from ever being *sent*.
3. **Log a canary on any non-CLI queue run** — a detection tripwire on `action_scheduler_before_process_queue`, not a block.
4. **Unhook the queue runner outside WP-CLI** — removes Action Scheduler's own `action_scheduler_run_queue` callback at `init:100`, turning WP-Cron, the async loopback, and a direct hook call alike into no-ops everywhere except WP-CLI.

The stack seeds a queue of probe actions, drives each entry point (WP-Cron, direct HTTP to `wp-cron.php`, the async-request `admin-ajax.php` endpoint, an authenticated admin page load, a manual "Run" click, and the WP-CLI controls) against every relevant combination of armed guards, and records what actually drained — not just what HTTP status came back. See [`## Findings this stack cannot demonstrate`](#findings-this-stack-cannot-demonstrate) and [`## Deviations from the production environment`](#deviations-from-the-production-environment-these-findings-came-from) before treating any negative result here as generalizable.

## Quick start

```
bin/stack up
bin/stack seed
bin/guard arm 1 2 3 4
bin/stack preflight
bin/stack measure-http http-cron-armed
bin/stack exec wp action-scheduler run
bin/stack down
```

`bin/stack up` builds the image (WordPress + Action Scheduler, pinned per `docker/Dockerfile`) if needed, starts the container, and prints the URL it published — a port derived from a hash of this checkout's own path, so multiple worktrees/clones can run the stack side by side without colliding. `bin/stack seed` populates 50 pending probe actions; `bin/guard arm 1 2 3 4` activates all four guard sections; `bin/stack preflight` asserts the queue is in a state a measurement can trust before you run one; `bin/stack measure-http http-cron-armed` GETs `wp-cron.php` and writes a result record under `results/`; `bin/stack exec wp action-scheduler run` proves the CLI control still drains the queue regardless of the guards above (WP-CLI is always exempt). `bin/stack down` tears the containers down.

To reproduce the full, committed matrix and its rendered report instead of running scenarios by hand, see `bin/measure-matrix` and `bin/generate-report` below.

## Verb reference

### `bin/stack`

```
bin/stack up                                  # build (if needed) and start, print the URL
bin/stack down [args...]                      # stop and remove containers
bin/stack shell                               # interactive shell in the wordpress container
bin/stack exec <cmd>                          # run <cmd> in the wordpress container, e.g. wp-cli
bin/stack seed [count] [--due-now]            # seed <count> (default 50) pending probe actions
bin/stack reset [count] [--due-now]           # clear transient/claims/leftovers, then re-seed
bin/stack preflight                           # assert preconditions, emit a JSON snapshot
bin/stack measure <control>                   # run a CLI control, write a result record
                                               #   <control>: wp-cron | action-scheduler
bin/stack measure-http <label>                # GET wp-cron.php, write a result record
bin/stack measure-async-ajax <scenario-label> # POST unauthenticated to Action Scheduler's
                                               # async-request ajax action, write a result record
bin/stack measure-admin-page-load <label>     # authenticated wp-admin page load, write a result record
bin/stack measure-manual-run <label>          # authenticated manual "Run" click from the
                                               # Scheduled Actions admin screen, write a result record
bin/stack set-disable-wp-cron <true|false>    # flip DISABLE_WP_CRON at runtime, no image rebuild
bin/stack occupancy [count]                   # measure worker occupancy during a drain
bin/stack measure-fastcgi-isolation           # drive the isolated fastcgi_finish_request()
                                               # proof files (issue #34), write a result record
```

Notes worth knowing before you rely on any of these:

- `seed`/`reset`'s `--due-now` flag seeds actions due immediately instead of the default ~5-minutes-in-the-future schedule — needed before measuring a due-now control such as `wp cron event run --due-now` or `wp action-scheduler run`.
- `preflight` fails loudly (non-zero exit) unless the pending count is non-zero, the queue-runner callback is attached, no `doing_cron` transient is set, and the claims table is empty — the exact traps the original findings' methodology section warns produce a convincing false result. Every `measure*` subcommand runs this same check again internally before trusting its own result.
- Every `measure*` subcommand writes its result record to `results/` (or `results/matrix/<run>/` when `bin/measure-matrix` overrides `WPCAS_RESULTS_DIR`) only once the underlying command has exited `0` — a failed run never leaves a zero-byte or partial file behind that could be mistaken for a real record.
- `set-disable-wp-cron` recreates the WordPress container with a new env value (no rebuild) — this is how the "fleet caveat" scenario (guard section 1 only blocks while `DISABLE_WP_CRON` is true) gets exercised.
- `occupancy [count]` seeds `count` due-now actions, triggers Action Scheduler's async dispatch via an admin-context HTTP hit, and fires waves of concurrent front-end requests at increasing concurrency while polling the drain's pending count out-of-band via WP-CLI, never over HTTP — so polling itself never consumes one of the PHP-FPM pool children being measured. It prints one finished JSON record naming the server model explicitly (`nginx + php-fpm`), with the occupancy figure's own basis citing PHP-FPM's one-request-per-child model (see issue #37).
- `measure-fastcgi-isolation` takes no label/scenario argument — unlike the other `measure*` verbs, it drives one fixed pair of requests (see [`## Caveats`](#caveats)) and writes one result record carrying each proof file's observable status (what the client read) and post-flush status (what that file recorded server-side, independently of anything a client could ever read back).

### `bin/guard`

```
bin/guard arm [section...]   # activate exactly these sections (1-4); no arguments disarms everything
bin/guard status             # report which sections are armed, without changing anything
```

Each section is a file in `docker/mu-plugins-available/`; arming copies it into `docker/mu-plugins/` (bind-mounted into the container, so it takes effect on the next request with no rebuild or restart); disarming removes the copy. There is no internal flag that changes a guard's behaviour — presence in `mu-plugins/` is the only toggle, so whatever runs during a scenario is exactly the code that would ship. `bin/guard arm` with no arguments disarms all four sections at once.

### `bin/measure-matrix [seed-count]`

Drives every scenario end to end, unattended: both CLI controls, all three HTTP vectors in their armed/unarmed states, the unhook-timing table, and the worker-occupancy measurement. Requires `bin/stack up` to already be running. For every ordinary scenario it arms exactly the guard sections that scenario needs, flips `DISABLE_WP_CRON` only when the scenario requires it, resets and preflights (up to 3 attempts total — initial attempt plus up to 2 retries — a straggling async-dispatch chain from a preceding unarmed scenario settling late is a genuine, honestly-observed transient, not a fabricated pass), and only then runs the actual measurement. A preflight or measurement failure is recorded as an explicit **negative** result record (never silently dropped or retried into a different answer) and the matrix continues to the next scenario. Every record from one run — positive or negative — lands under a single timestamped directory, `results/matrix/<UTC-timestamp>/`, kept separate from the individual example records committed directly under `results/`.

### `bin/generate-report [run-directory] [output-file]`

Renders one `bin/measure-matrix` run's committed JSON records into `docs/measurements/full-scenario-matrix-report.md` (the default output path). Reads exclusively from `results/matrix/<run>/` — never the top-level `results/` directory — and defaults to the most recently *named* (not most recently modified) run directory, since a fresh git clone collapses every checked-out file's mtime to checkout time. Every figure in the rendered report is read verbatim from a field in a committed record; nothing is computed, rounded, or estimated. A scenario that produced a negative result is rendered under its own "Not verified" section, never folded into the scenario table as if it had succeeded.

### Other scripts in `bin/`

- **`bin/measure-unhook-timing`** — issue #8's dedicated driver for the six-stage `has_action()` timing table (see [`docs/measurements/unhook-timing-4.0.0.md`](docs/measurements/unhook-timing-4.0.0.md)). Makes two requests against an already-running stack (guard disarmed, then only section 4 armed), copies its own measurement probe into `docker/mu-plugins/` for the duration and removes it again on exit. `bin/measure-matrix` calls this internally; it can also be run standalone once `bin/stack up` is running.
- **`bin/occupancy-report.php`** — the pure-PHP second half of `bin/stack occupancy`: reads the raw timing/pending-count facts `bin/stack occupancy` gathered as JSON on stdin, and prints the finished occupancy record on stdout. Not meant to be run standalone (it expects the facts JSON `bin/stack occupancy` produces internally).

## Results

- Rendered report: [`docs/measurements/full-scenario-matrix-report.md`](docs/measurements/full-scenario-matrix-report.md) — generated by `bin/generate-report` from the committed run at `results/matrix/20260802T152248Z/`.
- Unhook-timing writeup: [`docs/measurements/unhook-timing-4.0.0.md`](docs/measurements/unhook-timing-4.0.0.md).
- Raw records: [`results/matrix/`](results/matrix/) (full-matrix runs) and [`results/`](results/) (individual example records committed while building the earlier, single-issue scenarios).
- `fastcgi_finish_request()` isolation proof (issue #34): [`results/20260801T133253Z-fastcgi-isolation.json`](results/20260801T133253Z-fastcgi-isolation.json) — `flush-then-status`'s observable status is 200 while its post-flush status is 403; `status-then-flush`'s (the control) observable status is 403, matching its own post-flush status. See [`## Caveats`](#caveats) for why the two files this record measures are deliberately web-reachable.

### The response-masking finding (issue #35)

[`results/20260802T021813Z-http-cron-armed.json`](results/20260802T021813Z-http-cron-armed.json) is the scenario the "Findings this stack cannot demonstrate" section below used to say had no place in this repo. From one request against the nginx + PHP-FPM stack (issue #29), it records: `http_status: 200` — the client-observable status, already flushed and closed by core's `wp-cron.php` calling `fastcgi_finish_request()` before any mu-plugin, including guard section 1, ever runs; `post_flush_status: 403` — the status guard section 1 (`docker/mu-plugins-available/10-block-http-cron.php`) actually set with its own `http_response_code(403)` call, proven via its own post-flush self-report (`docker/wp-cli/lib/guard-block.php`), independent of anything the flush already closed out; and `outcome.drained: 0` — nothing left the queue. All three come from the single request `server_observed` and `post_flush_log_line` both name by the same `X-Wpcas-Request-Id`.

One claim this ticket set out to verify rather than assume: whether the process manager (PHP-FPM), not just the client, ends up recording the flushed status or the guard's later one. Checked directly against this running stack, repeatedly reproduced: `server_observed.fpm_status` reads back 200, the same as `server_observed.web_status` (nginx) and `http_status` — the flush closes out both access logs' idea of this request's status right along with the client's. Neither access log carries any trace of the 403 the guard genuinely set; the guard's own self-report is the only source that does. Publishing that as the answer, not the originally-assumed alternative, is the point of checking instead of assuming.

### Versions measured

- **Action Scheduler: 4.0.0.** Pinned as the single source of truth in `docker/Dockerfile` (`ARG ACTION_SCHEDULER_VERSION=4.0.0`), and independently confirmed by reading `ActionScheduler_Versions::instance()->latest_version()` back from a live request — see the `action_scheduler_version` field in both raw JSON responses in [`docs/measurements/unhook-timing-4.0.0.md`](docs/measurements/unhook-timing-4.0.0.md) and in [`docs/measurements/full-scenario-matrix-report.md`](docs/measurements/full-scenario-matrix-report.md#unhook-timing-table-issue-8). The two sources agree; there is no discrepancy to resolve.
- **WordPress: not pinned, and not recorded in any committed result.** `docker/Dockerfile` runs `wp core download --locale=en_US` with no `--version`, so the image installs whichever WordPress release is current at build time — not a fixed, reproducible number — and no committed result record, preflight snapshot, or measurement doc captures the installed version back from a live request. Nothing in this repo's findings depends on a specific WordPress version (the guards hook `init`/`wp_loaded`/`action_scheduler_*` actions and `PHP_SAPI`, none of which are WordPress-version-specific), but this README does not state a WordPress version because none was ever measured — see `## Decisions` in this branch's PR description for why that gap is being shipped as-is rather than backfilled.

## Caveats

- **The `fastcgi_finish_request()` isolation proof files are deliberately web-reachable.** `flush-then-status.php` and `status-then-flush.php` (issue #34; source at `docker/fastcgi-isolation/`) are two small, static files served directly from the document root at `/flush-then-status.php` and `/status-then-flush.php`, entirely independent of WordPress, Action Scheduler, or any mu-plugin. That independence is the whole point: a reader can convince themselves `fastcgi_finish_request()` alone causes the observable-status/post-flush-status divergence, without reading — or trusting — the rest of this harness. Their being reachable on any running instance of this stack is the trade-off that isolation costs, documented here rather than left as an accident — the same "acceptable for a localhost-only PoC" rationale `docker/nginx/default.conf`'s own docstring already applies to this stack's other document-root exposure gaps.

## Deviations from the production environment these findings came from

This stack differs from the environment the original findings (`handoff-wp-cron-as-post-and-poc.md`) were measured against in one remaining way. It is a real difference in how the code executes, not just cosmetic — read this section before treating a result here as a stand-in for the original environment's behaviour.

1. **The database is SQLite, not MySQL.** `docker/Dockerfile` installs the SQLite Database Integration plugin's `db.php` drop-in (with `WP_SQLITE_AST_DRIVER` enabled) instead of connecting to MySQL/MariaDB. Action Scheduler's claim query is an `UPDATE ... JOIN (subquery) ... SET ...`, which needs the plugin's newer, grammar-based AST translator to run on SQLite at all (its legacy regex-based translator cannot rewrite that shape of query). Every scenario in this repo that shows a successful drain is therefore also, incidentally, evidence that the AST translator handles Action Scheduler's claim query correctly — but it does **not** invalidate any of the guard/entry-point findings themselves, none of which depend on the specific SQL Action Scheduler issues or on MySQL-specific behaviour. It **does** mean this stack carries a residual risk the original MySQL environment did not: a future "zero drained" result on this stack should first rule out an AST-driver translation gap before being read as a guard finding, exactly the way this repo's own Dockerfile comments already flag.

Two deviations that used to be listed here — the built-in server having no `fastcgi_finish_request()` call to mask the guard's response, and the SAPI string reading `cli-server` instead of `fpm-fcgi` — no longer apply: issue #29 replaced PHP's built-in server with nginx + PHP-FPM as this repo's only server model, and issue #35 measured the previously-undemonstrable masking finding for real (see [The response-masking finding (issue #35)](#the-response-masking-finding-issue-35) above, and [`docs/adr/0001-nginx-php-fpm-replaces-the-built-in-server.md`](docs/adr/0001-nginx-php-fpm-replaces-the-built-in-server.md) for why the server model was replaced rather than kept alongside PHP-FPM).

## Findings this stack cannot demonstrate

One of the original findings in `handoff-wp-cron-as-post-and-poc.md` is out of scope for this stack. Its absence from any result record here is not evidence it was disproven — it means the scenario was never run.

- **"A cron run is serial and fragile — one uncaught exception stalls every event behind it"** (`handoff-wp-cron-as-post-and-poc.md` §3.B and post-outline section 6), including the associated methodology trap that an unrelated fatal error earlier in a run can produce a false "0 drained" result that looks like a passing block. Nothing about this stack's server model or database makes this **technically** unreproducible — the handoff document itself notes a MinIO/S3 dependency is not required, only "a deliberate `add_action('init', ...)`-scheduled throwing event" (§5). But no scenario in `bin/measure-matrix`, and no mu-plugin under `docker/mu-plugins-available/`, deliberately introduces a fatal error mid-drain to demonstrate this. It was out of scope of the issues this repo implements (#1–#10); a reader should not read the absence of a "fragile serial run" scenario here as evidence that this stack's own 50/50 drains would necessarily survive an uncaught exception partway through — that specific claim was never tested against this codebase.

The other original finding this section used to list here — **"You cannot return an HTTP status from a mu-plugin on `wp-cron.php`"** (`handoff-wp-cron-as-post-and-poc.md` §3.A and post-outline section 5) — moved out of this section in issue #35: it once read as "structurally impossible to reproduce on this stack" back when this repo ran on PHP's built-in server (no `fastcgi_finish_request()`, hence no masking to reproduce); issue #29 replaced that server model with nginx + PHP-FPM, and issue #35 measured the scenario for real. See [The response-masking finding (issue #35)](#the-response-masking-finding-issue-35) under Results above.

## License

MIT — see [`LICENSE`](LICENSE).
