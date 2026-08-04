# WP-Cron + Action Scheduler cron-runner-policy PoC

A bootable, single-command WordPress + Action Scheduler stack that reproduces the measured findings behind a `Cron Runner Policy` mu-plugin: what happens when you block `wp-cron.php` over HTTP, suppress Action Scheduler's async loopback, canary any non-CLI queue run, and unhook the queue runner outside WP-CLI. Every scenario in this repo writes a JSON result record to disk, so every number quoted anywhere in this repo (or the post it accompanies) traces back to a committed file, not a screenshot or a memory of a test run.

The stack runs on **nginx + PHP-FPM** — the same server model the original findings were measured under, and the only one this repo contains. That matters beyond fidelity for its own sake: the centrepiece finding (a guard on the cron entry point cannot return a status any client can read, because core's `wp-cron.php` calls `fastcgi_finish_request()` before mu-plugins load) only exists under PHP-FPM, and was structurally unreproducible under the PHP built-in server this stack originally used. See [`docs/adr/0001-nginx-php-fpm-replaces-the-built-in-server.md`](docs/adr/0001-nginx-php-fpm-replaces-the-built-in-server.md) for that swap and what it cost.

The original findings this repo reproduces came from a non-public environment, and the handoff notes describing them are no longer in this repo (removed in `382dd19`; git history is their archive). What survives publicly is derived: [`CONTEXT.md`](CONTEXT.md) for the vocabulary every document here uses, [`docs/adr/`](docs/adr/) for the decisions, and [`docs/blog-post-draft.md`](docs/blog-post-draft.md) for the write-up the measurements support.

## Purpose

Five guard sections are individually toggled by `bin/guard`. Four act inside WordPress, as mu-plugins in `docker/mu-plugins-available/`:

1. **Block HTTP requests to the cron entry point** — a mu-plugin that 403s any request that looks like `wp-cron.php` running outside CLI, while `DISABLE_WP_CRON` is true.
2. **Suppress Action Scheduler's async dispatch** — forces `action_scheduler_allow_async_request_runner` to `false`, stopping Action Scheduler's own loopback POST to `admin-ajax.php` from ever being *sent*.
3. **Log a canary on any non-CLI queue run** — a detection tripwire on `action_scheduler_before_process_queue`, not a block.
4. **Unhook the queue runner outside WP-CLI** — removes Action Scheduler's own `action_scheduler_run_queue` callback at `init:100`, turning WP-Cron, the async loopback, and a direct hook call alike into no-ops everywhere except WP-CLI.

The fifth acts in front of PHP, in nginx, and is what makes the comparison this repo is built around possible:

5. **Block the cron entry point before PHP bootstraps** — an exact-match `location` for `/wp/wp-cron.php` in `docker/nginx/default.conf` that returns 403 while a marker file is present. Because nginx refuses the request before any PHP runs, this is the only one of the five whose block a client can actually see — and, because nginx cannot read a PHP constant, the only one that cannot honour the fleet caveat. See [`docs/adr/0003-guard-section-5-at-the-nginx-layer.md`](docs/adr/0003-guard-section-5-at-the-nginx-layer.md) for that trade-off, and [`## The masking finding`](#the-masking-finding-a-guard-inside-php-cannot-return-a-status) for the pair of measurements it exists to complete.

A guard section is therefore not the same thing as a mu-plugin file, and `bin/guard`'s registry is deliberately layer-agnostic: each section carries its own artefact, its own source directory, and its own arming destination. There is still exactly one arming concept across all five — copy an artefact in to arm, remove it to disarm — and still no reload or restart: WordPress re-scans `mu-plugins/` on every request, and nginx re-tests for section 5's marker on every request.

The stack seeds a queue of probe actions, drives each entry point (WP-Cron, direct HTTP to `wp-cron.php`, the async-request `admin-ajax.php` endpoint, an authenticated admin page load, a manual "Run" click, and the WP-CLI controls) against every relevant combination of armed guards, and records what actually drained — not just what HTTP status came back. See [`## Caveats`](#caveats), [`## Findings this stack cannot demonstrate`](#findings-this-stack-cannot-demonstrate) and [`## Deviations from the production environment`](#deviations-from-the-production-environment-these-findings-came-from) before treating any negative result here as generalizable.

## The server model

Two Compose services, both pinned in exactly one place each:

- **nginx 1.27-alpine** (`docker-compose.yml`'s `nginx` service `image:` tag — its only source of truth, since it has no Dockerfile of its own). Serves the document root directly, hands every existing `.php` file to PHP-FPM over FastCGI, and refuses Composer's manifests, `vendor/`, and dotfiles anywhere under the webroot — including the SQLite data file at `wp/wp-content/database/.ht.sqlite`. It also holds guard section 5's own `location` for the cron entry point; both PHP-serving locations pull their FastCGI directives from one shared include ([`docker/nginx/php-fastcgi.conf`](docker/nginx/php-fastcgi.conf)) so the cron entry point can never end up served differently from every other `.php` file. Config: [`docker/nginx/default.conf`](docker/nginx/default.conf).
- **PHP 8.3-fpm-bookworm** (`ARG PHP_VERSION` in [`docker/Dockerfile`](docker/Dockerfile); `docker-compose.yml` deliberately passes no `args:` override, so the two files cannot silently disagree). The SAPI every served request runs under is genuinely `fpm-fcgi` — the string this repo's canary and probe records report, and the one a reader can grep production logs for.

The webroot is provisioned by Composer at image build time from a committed lockfile ([`docker/composer.json`](docker/composer.json), [`docker/composer.lock`](docker/composer.lock)): WordPress core under `/wp`, Action Scheduler and the SQLite Database Integration plugin in `wp/wp-content/plugins/`, and `wp-config.php` one directory above core. `WP_SITEURL` carries the `/wp` prefix; `WP_HOME` does not, so the site itself still resolves at the document root. This is deliberately not Bedrock — see ADR-0001's "Layout" section.

Every ceiling this stack runs under is pinned explicitly and read back into the preflight snapshot (and therefore into every result record) from the same bytes the servers are actually running with, never restated as a second literal that could drift:

| Ceiling | Value | Pinned in |
|---|---|---|
| PHP-FPM pool | `pm = static`, `pm.max_children = 6` | `docker/Dockerfile` (`zz-wpcas-pool.conf`) |
| PHP-FPM `request_terminate_timeout` | 35s | `docker/Dockerfile` (same file) |
| PHP `max_execution_time` | 30s | `docker/Dockerfile` (`execution-time.ini`) |
| nginx `fastcgi_read_timeout` | 40s | [`docker/nginx/fastcgi-read-timeout.conf`](docker/nginx/fastcgi-read-timeout.conf) |

`max_execution_time` is read out of that ini file's own text rather than via `ini_get()`, because PHP's CLI SAPI — what every `wp eval-file` invocation, preflight included, runs as — unconditionally resets it to `0` after ini parsing. [`docker/wp-cli/lib/server-config.php`](docker/wp-cli/lib/server-config.php)'s module docblock has the full story.

Because nginx and PHP-FPM are separate services, two things that used to be free now need arranging, and both are: the webroot is a shared named volume (nginx mounts it read-only), and any HTTP loopback built from `WP_HOME`/`admin_url()` inside the php-fpm container is rewritten to reach nginx by its Compose service name (`docker/mu-plugins/wpcas-internal-loopback-resolve.php`) — including Action Scheduler's own async dispatcher, which this repo does not own and cannot edit.

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

`bin/stack up` builds the image (Composer-provisioned WordPress + Action Scheduler, both pinned per `docker/composer.lock`) if needed, starts both containers, and prints the URL it published — a port derived from a hash of this checkout's own path, so multiple worktrees/clones can run the stack side by side without colliding. `bin/stack seed` populates 50 pending probe actions; `bin/guard arm 1 2 3 4` activates all four guard sections; `bin/stack preflight` asserts the queue *and* the running software are in a state a measurement can trust before you run one; `bin/stack measure-http http-cron-armed` GETs `wp-cron.php` and writes a result record under `results/`; `bin/stack exec wp action-scheduler run` proves the CLI control still drains the queue regardless of the guards above (WP-CLI is always exempt). `bin/stack down` tears the containers down.

To reproduce the full, committed matrix and its rendered report instead of running scenarios by hand, see `bin/measure-matrix` and `bin/generate-report` below.

## Verb reference

### `bin/stack`

```
bin/stack up                                  # build (if needed) and start, print the URL
bin/stack down [args...]                      # stop and remove containers
bin/stack shell                               # interactive shell in the php-fpm container
bin/stack exec <cmd>                          # run <cmd> in the php-fpm container, e.g. wp-cli
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
                                               # proof files, write a result record
```

Notes worth knowing before you rely on any of these:

- `shell` and `exec` both target the **php-fpm** container: that is where WP-CLI, the measurement tooling (`/opt/wpcas-tools/`), and WordPress itself live. nginx is a separate service with nothing of this repo's own in it beyond two config files.
- `seed`/`reset`'s `--due-now` flag seeds actions due immediately instead of the default ~5-minutes-in-the-future schedule — needed before measuring a due-now control such as `wp cron event run --due-now` or `wp action-scheduler run`.
- `preflight` fails loudly (non-zero exit) unless the pending count is non-zero, the queue-runner callback is attached, no `doing_cron` transient is set, the claims table is empty, the live WordPress and Action Scheduler versions match what `docker/composer.lock` resolved, and all four ceilings in the table above are readable back from the running stack. Every `measure*` subcommand runs this same check again internally before trusting its own result. The version assertion is the one that looks like paranoia and is not — see [`## Caveats`](#caveats).
- Every `measure*` subcommand writes its result record to `results/` (or `results/matrix/<run>/` when `bin/measure-matrix` overrides `WPCAS_RESULTS_DIR`) only once the underlying command has exited `0` — a failed run never leaves a zero-byte or partial file behind that could be mistaken for a real record.
- `measure-http` sends a unique `X-Wpcas-Request-Id` header on the request it measures, then correlates **three** independently recorded statuses by that id into the record's `server_observed` field: nginx's own access log (shared into the php-fpm container read-only through a named volume), PHP-FPM's own access log, and the status PHP itself still held at shutdown, after the response had already been closed (`docker/mu-plugins/00-wpcas-post-flush-status-probe.php`). Together with `http_status` — the client's own report — that gives four sources for one request, which are allowed to disagree; see [`## The masking finding`](#the-masking-finding-a-guard-inside-php-cannot-return-a-status).
- Under PHP-FPM, `pending_after` for an HTTP vector is read from a bounded settle poll, not immediately after the measuring request returns: `wp-cron.php` flushes and closes its response *before* it drains anything, so a synchronous read would race still-running background work and misreport a false "0 drained" on every unarmed run.
- `set-disable-wp-cron` recreates the php-fpm container with a new env value (no rebuild) — this is how the "fleet caveat" scenario (guard section 1 only blocks while `DISABLE_WP_CRON` is true) gets exercised.
- `occupancy [count]` seeds `count` due-now actions, triggers Action Scheduler's async dispatch via an admin-context HTTP hit, and fires waves of concurrent front-end requests at increasing concurrency (3, 6, 12, 24 — bracketing the pool: below, at, and twice above it) while polling the drain's pending count out-of-band via WP-CLI, never over HTTP, so polling never occupies one of the children being measured. The pool size in its record is read back from the running pool's own `pm.max_children`; a pool size that cannot be read is a hard failure rather than a guessed default, because attributing a drain's cost to a known pool is the whole point of the measurement.
- `measure-fastcgi-isolation` takes no label/scenario argument — unlike the other `measure*` verbs, it drives one fixed pair of requests (see [`## The masking finding`](#the-masking-finding-reproducible-here-recorded-in-isolation)) and writes one result record carrying each proof file's observable status (what the client read) and post-flush status (what that file recorded server-side, independently of anything a client could ever read back).

### `bin/guard`

```
bin/guard arm [section...]   # activate exactly these sections (1-5); no arguments disarms everything
bin/guard status             # report which sections are armed, without changing anything
```

Each registered section has an artefact, a source directory, and a destination it is copied into when armed. Sections 1–4: a mu-plugin from `docker/mu-plugins-available/` into `docker/mu-plugins/`, bind-mounted into the php-fpm container. Section 5: a marker file from `docker/nginx-guards-available/` into `docker/nginx-guards/`, bind-mounted into the nginx container at `/etc/wpcas/guards`. Disarming removes the copy. There is no internal flag that changes a guard's behaviour — presence in its destination is the only toggle, so whatever runs during a scenario is exactly the code that would ship. `bin/guard arm` with no arguments disarms every section at once.

Section 5 is a marker rather than an included nginx config snippet for one reason: an include would only take effect on reload, which would make section 5 the one section needing a second arming mechanism. nginx tests for the file per request, so it arms exactly as immediately as a mu-plugin appearing in `mu-plugins/` does. It also needs no CLI exemption: a `wp` process never traverses nginx, so WP-CLI is untouched by construction rather than by a runtime `PHP_SAPI` check like sections 1 and 3 need.

`docker/mu-plugins/` also holds probe mu-plugins that are loaded unconditionally and are *not* guard sections: the probe that records which process executed each probe action, an observer on the async-dispatch filter, and the internal-loopback rewrite the two-service split requires. They carry the instrumentation measurements depend on, which is exactly why they have no arm/disarm step to forget.

### `bin/measure-matrix [seed-count]`

Drives every scenario end to end, unattended: both CLI controls, all three HTTP vectors in their armed/unarmed states, the unhook-timing table, and the worker-occupancy measurement. Requires `bin/stack up` to already be running. For every ordinary scenario it arms exactly the guard sections that scenario needs, flips `DISABLE_WP_CRON` only when the scenario requires it, resets and preflights (up to 3 attempts total — initial attempt plus up to 2 retries — a straggling async-dispatch chain from a preceding unarmed scenario settling late is a genuine, honestly-observed transient, not a fabricated pass), and only then runs the actual measurement. A preflight or measurement failure is recorded as an explicit **negative** result record (never silently dropped or retried into a different answer) and the matrix continues to the next scenario. Every record from one run — positive or negative — lands under a single timestamped directory, `results/matrix/<UTC-timestamp>/`, kept separate from the individual example records committed directly under `results/`. The isolated `fastcgi_finish_request()` proof (`bin/stack measure-fastcgi-isolation`) is not part of the matrix and is driven on its own.

### `bin/generate-report [run-directory] [output-file]`

Renders one `bin/measure-matrix` run's committed JSON records into `docs/measurements/full-scenario-matrix-report.md` (the default output path). Reads exclusively from `results/matrix/<run>/` — never the top-level `results/` directory — and defaults to the most recently *named* (not most recently modified) run directory, since a fresh git clone collapses every checked-out file's mtime to checkout time. Every figure in the rendered report is read verbatim from a field in a committed record; nothing is computed, rounded, or estimated. A scenario that produced a negative result is rendered under its own "Not verified" section, never folded into the scenario table as if it had succeeded.

### Other scripts in `bin/`

- **`bin/measure-unhook-timing`** — the dedicated driver for the six-stage `has_action()` timing table (see [`docs/measurements/unhook-timing-4.0.0.md`](docs/measurements/unhook-timing-4.0.0.md)). Makes two requests against an already-running stack (guard disarmed, then only section 4 armed), copies its own measurement probe into `docker/mu-plugins/` for the duration and removes it again on exit. `bin/measure-matrix` calls this internally; it can also be run standalone once `bin/stack up` is running.
- **`bin/occupancy-report.php`** — the pure-PHP second half of `bin/stack occupancy`: reads the raw timing/pending-count facts `bin/stack occupancy` gathered as JSON on stdin, and prints the finished occupancy record on stdout. Not meant to be run standalone (it expects the facts JSON `bin/stack occupancy` produces internally).

The pure, WP-free halves of the tooling (config parsing, log parsing, record building, assertions) have unit tests under `tests/`, runnable with plain `php` — no container or WordPress bootstrap required, e.g. `php tests/server-config.test.php`.

## Results

- Rendered report: [`docs/measurements/full-scenario-matrix-report.md`](docs/measurements/full-scenario-matrix-report.md) — generated by `bin/generate-report` from the committed run at `results/matrix/20260804T175620Z/`, measured on nginx + PHP-FPM. Every record measured under the retired built-in server has been deleted rather than left to mislead: nothing in the repo can reproduce them, and git history is their archive.
- Unhook-timing writeup: [`docs/measurements/unhook-timing-4.0.0.md`](docs/measurements/unhook-timing-4.0.0.md) — re-measured under the new server model, with every value unchanged (when a callback is attached during a request is a function of WordPress's own hook order, not of the server in front of it).
- Raw records: [`results/matrix/`](results/matrix/) (full-matrix runs) and [`results/`](results/) (individual example records). Every probe record in them reports `sapi: fpm-fcgi`.
- `fastcgi_finish_request()` isolation proof: [`results/20260804T174912Z-fastcgi-isolation.json`](results/20260804T174912Z-fastcgi-isolation.json) — `flush-then-status` records an observable 200, an *attempted* status of 403, `set_call_returned: false`, and a post-flush status read back as **200**: PHP refused the status change outright. `status-then-flush`, the control, makes the identical call before flushing and records 403, accepted and observable. Driven separately from the matrix by `bin/stack measure-fastcgi-isolation`, since it proves a PHP mechanism rather than measuring a queue scenario.

### Versions measured

Everything the image installs is pinned in `docker/composer.lock` and asserted back from the running site by every preflight (see ADR-0002), so a result record carries the versions it was actually measured against:

- **WordPress: 7.0.2** — `johnpbloch/wordpress-core`, pinned in `docker/composer.json` and resolved in `docker/composer.lock`. This closes the gap earlier versions of this README had to admit ("not pinned, and not recorded in any committed result"): the live version is now read back from the running site into every preflight snapshot, alongside the lockfile's own value, and a mismatch is a loud refusal to measure.
- **Action Scheduler: 4.0.0** — `wpackagist-plugin/action-scheduler`, same lockfile, and independently confirmed by reading `ActionScheduler_Versions::instance()->latest_version()` back from a live request (the `action_scheduler_version` field in both raw JSON responses in [`docs/measurements/unhook-timing-4.0.0.md`](docs/measurements/unhook-timing-4.0.0.md)).
- **SQLite Database Integration: 2.2.23**, with `WP_SQLITE_AST_DRIVER` enabled.
- **PHP: 8.3-fpm-bookworm**; **nginx: 1.27-alpine.**

## The masking finding: a guard inside PHP cannot return a status

The finding this project exists to demonstrate above all others is that a guard on the cron entry point cannot return a status any client can read. Core's `wp-cron.php` calls `fastcgi_finish_request()` before it loads mu-plugins, so under PHP-FPM the response is already flushed and closed as a 200 by the time a guard's `exit` runs. The guard genuinely stopped the queue from draining; the client saw 200 anyway. **On PHP-FPM, a 200 is not evidence the guard failed.**

Measuring it turned the finding out to be sharper than "the client can't see the 403". Four independent sources were checked for the same request, and the armed row is **indistinguishable from the unarmed one on every single status** — while draining nothing instead of everything:

| Source | `http-cron-unarmed` | `http-cron-armed` (section 1) | `nginx-cron-armed` (section 5) |
|---|---|---|---|
| The client, off the wire | 200 | 200 | 403 |
| nginx's access log | 200 | 200 | 403 |
| PHP-FPM's access log | 200 | 200 | none — PHP never ran |
| PHP's own status at shutdown | 200 | 200 | none — PHP never ran |
| **Probe actions drained** | **50 of 50** | **0 of 50** | **0 of 50** |

So the guard worked, and its `http_response_code( 403 )` reached *nothing* — not the client, not either server's log, not even PHP's own state. Every figure above is rendered from that run's own records in [`docs/measurements/full-scenario-matrix-report.md`](docs/measurements/full-scenario-matrix-report.md). The reason is measured directly, independently of WordPress, by the isolation proof (`docker/fastcgi-isolation/`, driven by `bin/stack measure-fastcgi-isolation`): after `fastcgi_finish_request()`, `http_response_code( 403 )` **returns false and changes nothing** — the read-back is still 200. Its control file, `status-then-flush.php`, makes the same call *before* flushing, and there the status is accepted and observable as 403. Only the ordering differs. That is why the isolation files are independent of everything else here: a reader can convince themselves of the mechanism without reading — or trusting — the rest of this harness.

Which is why guard section 5 exists — the third column above. Move the identical block one layer out, into nginx, and the client reads a real 403 with nothing drained, because nothing was flushed first. The cost is the committed counter-example: with `DISABLE_WP_CRON` false, section 1 goes inert and the queue drains 50 of 50 (a site depending on the loopback keeps its cron), while section 5 blocks anyway — 403, zero drained, cron genuinely lost. An observable status *or* a fleet-safe conditional block, at one layer, but not both.

Two honest limits on the above. The post-flush reading is a **negative** result: it rules out a hidden server-side 403 rather than exhibiting one, and it is worth having for exactly that reason. And a status is never what any outcome in this repo rests on — outcome is always the pending-count delta plus the probe's own execution log, whatever the four sources say.

## Caveats

- **The shared webroot volume goes stale silently, and preflight only catches part of it.** nginx and PHP-FPM share the Composer-provisioned webroot as a named volume, which is populated once, on first start, from whatever the image contained. A later image rebuild does not reach an already-existing volume. For the *pinned packages* the failure mode is loud: preflight reads the live WordPress and Action Scheduler versions back from the running site, compares them against a copy of the lockfile kept outside the volume, and refuses to measure on a mismatch — see [`docs/adr/0002-shared-webroot-volume-staleness-detected-not-prevented.md`](docs/adr/0002-shared-webroot-volume-staleness-detected-not-prevented.md), the ADR most worth reading before touching preflight. For *this repo's own files baked into the webroot* — the two `fastcgi_finish_request()` proof files — there is no such check, and the trap is real rather than theoretical: editing them, rebuilding, and re-measuring silently re-measures the old copies, because their versions are not pinned in any lockfile and nothing reads them back. Remove the webroot volume (`bin/stack down -v`, then `bin/stack up`) after changing anything the image places under the document root.
- **Two of the four statuses arrive after the response does, so they are polled for.** PHP-FPM's access line and the post-flush probe's line are both written only once the serving request has fully ended, which on an unarmed run is the same moment the drain finishes. `measure-http` therefore waits (bounded, 10s) for all three server-side sources before recording, and `measure-fastcgi-isolation` waits for a *new* log entry rather than any entry, since that log is append-only and outlives a run. Both bounds resolve to `null` rather than to a borrowed value, so a missing status stays missing in the record.
- **Core is Composer-managed and lives under `/wp`.** Nothing in the running container installs, updates, or downloads WordPress: the webroot is built by `composer install` against the lockfile at image build time. Editing core or plugin files in a running container is therefore a change to a volume, not to the image, and is lost the next time the volume is recreated. The `/wp` prefix also means entry-point paths read `/wp/wp-cron.php`, `/wp/wp-admin/admin-ajax.php`, and so on — which is deliberate, because it matches the paths the original environment's own canary output recorded — while the site itself still resolves unprefixed at the document root.
- **The `fastcgi_finish_request()` isolation proof files are deliberately web-reachable.** `flush-then-status.php` and `status-then-flush.php` (source at `docker/fastcgi-isolation/`) are served directly from the document root at `/flush-then-status.php` and `/status-then-flush.php`, independent of WordPress. That independence is the whole point (see the section above), and their being reachable on any running instance of this stack is the trade-off isolation costs — documented here rather than left as an accident. nginx denies Composer's manifests, `vendor/`, and dotfiles, but it has no allow-list for `.php` files: any existing one under the document root is executed. Acceptable for a localhost-only PoC; not a pattern to copy.

## Deviations from the production environment these findings came from

The server model is no longer one of these: nginx + PHP-FPM is what the original environment ran and what this stack runs, so `fastcgi_finish_request()` is present and the SAPI string is genuinely `fpm-fcgi`. One real difference remains.

1. **The database is SQLite, not MySQL.** `docker/composer.json` installs the SQLite Database Integration plugin and `docker/Dockerfile` places its `db.php` drop-in (with `WP_SQLITE_AST_DRIVER` enabled) instead of connecting to MySQL/MariaDB. Action Scheduler's claim query is an `UPDATE ... JOIN (subquery) ... SET ...`, which needs the plugin's newer, grammar-based AST translator to run on SQLite at all (its legacy regex-based translator cannot rewrite that shape of query). Every scenario in this repo that shows a successful drain is therefore also, incidentally, evidence that the AST translator handles Action Scheduler's claim query correctly — but it does **not** invalidate any of the guard/entry-point findings themselves, none of which depend on the specific SQL Action Scheduler issues or on MySQL-specific behaviour. It **does** mean this stack carries a residual risk the original MySQL environment did not: a future "zero drained" result on this stack should first rule out an AST-driver translation gap before being read as a guard finding, exactly the way this repo's own Dockerfile comments already flag.

## Findings this stack cannot demonstrate

One of the original findings is out of scope for this stack. Its absence from any result record here is not evidence it was disproven — it means the scenario was never run.

1. **"A cron run is serial and fragile — one uncaught exception stalls every event behind it"**, including the associated methodology trap that an unrelated fatal error earlier in a run can produce a false "0 drained" result that looks like a passing block. Nothing about this stack's server model or database makes this technically unreproducible — it needs only a deliberate `add_action('init', ...)`-scheduled throwing event, with no external dependency. But no scenario in `bin/measure-matrix`, and no mu-plugin under `docker/mu-plugins-available/`, deliberately introduces a fatal error mid-drain to demonstrate it. It was out of scope of the issues this repo implements; a reader should not read the absence of a "fragile serial run" scenario here as evidence that this stack's own 50/50 drains would necessarily survive an uncaught exception partway through — that specific claim was never tested against this codebase.

## License

MIT — see [`LICENSE`](LICENSE).
