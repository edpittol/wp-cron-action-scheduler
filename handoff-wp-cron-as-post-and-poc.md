# Handoff — WP-Cron + Action Scheduler: blog post draft & isolated PoC

**Date:** 2026-07-27
**Origin repo:** `/Users/pittol/Sites/criacao.cc` (branch `main`)
**Next session focus:** (1) draft a blog post on best practices for running WP-Cron + Action Scheduler; (2) build an **isolated** proof-of-concept that reproduces the measured scenarios outside this repo.

---

## 1. What happened in the previous session

The task was to empirically verify a `Cron Runner Policy` mu-plugin (block HTTP `wp-cron.php`, stop Action Scheduler async dispatch, canary on non-CLI queue runs, optional unhook). All three questions were answered with measurements, not code reading.

**Verdict: keep the mu-plugin, section 4 (the unhook) enabled.**

The full reasoning, with the measured numbers inline, now lives in the shipped file — **read it first, it is the primary artifact**:

- `/Users/pittol/Sites/criacao.cc/wordpress/mu-plugins/000-cron-runner-policy.php`
  (note: `wordpress/mu-plugins/*` is gitignored, so this is not in git history)

Durable notes, already written:

- `~/.config/ccsw/configs/aztec/projects/-Users-pittol-Sites-criacao-cc/memory/cron-runner-policy-findings.md`
- `~/.config/ccsw/configs/aztec/projects/-Users-pittol-Sites-criacao-cc/memory/criacao-cc-local-env-modes.md`

Do not re-derive any of the above; cite it.

---

## 2. The post outline (exists only here — not captured in any other artifact)

Ten sections were agreed in conversation. This is the thing to draft.

1. **Default WP-Cron is a capacity problem, not just a timing one** — a cron run occupies a PHP-FPM worker. Measured: client got its response in 0.217s; a single worker (`pid=7`) then worked ~20s draining 50 actions.
2. **`DISABLE_WP_CRON` is a scheduling switch, not access control** — it only stops `spawn_cron()` on page loads; `wp-cron.php` stays publicly executable.
3. **Action Scheduler has four entry points; closing one closes nothing** — (a) `wp-cron.php` → `WP_CRON_HOOK`; (b) async loopback dispatch (the `action_scheduler_allow_async_request_runner` filter gates **outbound dispatch only**); (c) direct POST to `admin-ajax.php?action=as_async_request_queue_runner` with a valid nonce — `handle()` calls `do_action('action_scheduler_run_queue')` with **no** `allow()` check; (d) manual run in the AS admin screen (`process_action()` directly, bypasses the hook).
4. **`remove_action()` timing is a silent failure mode** — must run *after* AS attaches, or it removes nothing without error.
5. **You cannot return an HTTP status from a mu-plugin on `wp-cron.php`** — the counter-intuitive centrepiece; see §3 below.
6. **A cron run is serial and fragile** — one uncaught exception stalls every event behind it; see §3 below.
7. **The CLI path behaves differently** — async loopback never fires under WP-CLI (`maybe_dispatch_async_request()` is gated on `is_admin()`); WP-CLI leaves no `doing_cron` transient; duty-cycle ceiling is `time_limit / 60` because `action_scheduler_run_queue` is on an `every_minute` schedule; `wp action-scheduler run` uses `ActionScheduler_WPCLI_QueueRunner` and bypasses `WP_CRON_HOOK`, `wp cron event run --due-now` does not.
8. **"Removing the web fallback" is usually a phantom cost** — with `DISABLE_WP_CRON` + blocked `wp-cron.php` + the allow filter, there is already no fallback; the supervisor is already the SPOF.
9. **Recommended architecture** — system scheduler + WP-CLI; block `wp-cron.php` at the web server (real 403, no PHP bootstrap); filter the async runner; unhook at `init:100`; canary on `action_scheduler_before_process_queue` for non-CLI; monitor supervisor liveness. Fleet caveat: if `mu-plugins` is shared, gate the block on `DISABLE_WP_CRON` so sites still relying on the loopback don't lose cron silently.
10. **Methodology and traps** — see §4 below.

Suggested lede: section 5 or section 6 — both are counter-intuitive and poorly covered elsewhere.

---

## 3. The two findings the PoC must reproduce above all

### A. `fastcgi_finish_request()` makes the 403 unobservable

Core `wp-cron.php` calls `fastcgi_finish_request()` at **line 28** — before `define('DOING_CRON', true)` (line 41) and before `require_once __DIR__ . '/wp-load.php'` (line 45), which is what loads mu-plugins. The response is flushed and closed as **200** before any plugin code runs.

Isolated proof (two one-line PHP files behind PHP-FPM):

| Script | Client sees |
|---|---|
| `http_response_code(403); exit;` | **403** |
| `fastcgi_finish_request(); http_response_code(403); exit;` | **200**, 0 bytes |

Consequences: PHP-FPM's access log records 403 while the client receives 200 — both logs correct. The block still works (the `exit` precedes all cron logic). **A 200 is not evidence the guard failed** — verify work, not status codes.

### B. An unrelated fatal error fakes a passing test

The first Q2 run showed 0 of 50 drained — apparently proving the threat didn't exist. The container log showed the real cause: a missing MinIO bucket made `wp_privacy_delete_old_export_files` throw an uncaught `S3Exception`, killing the run before `action_scheduler_run_queue` was reached. After creating the bucket, the same test drained 50/50.

This also contradicts a prior "later test showed zero drained" claim carried into the task brief — that result was almost certainly the same class of artifact.

---

## 4. Measured results to reproduce in the PoC

Harness: 50 probe actions on a hook with a deliberate 200ms cost, logging `PHP_SAPI`, pid and timestamp. Preconditions asserted before every attempt: pending count non-zero, `has_action()` non-false, `doing_cron` transient deleted, `wp_actionscheduler_claims` empty, supervisor stopped.

| Scenario | Result |
|---|---|
| GET `wp-cron.php`, guard **removed** | **50/50 drained** in ~20s, all `sapi=fpm-fcgi`, single pid |
| GET `wp-cron.php`, guard **active** | **0/50**, no hook executed, no `doing_cron` transient left |
| POST admin-ajax async runner, sections 1–3 only | **50/50 drained** in <5s, `sapi=fpm-fcgi` |
| POST admin-ajax async runner, section 4 **enabled** | **0/50**, 200 status |
| `wp cron event run --due-now`, section 4 enabled | `action_scheduler_run_queue` in 10.5s, **50 → 0** |
| `wp action-scheduler run`, section 4 enabled | "Found 10 scheduled tasks", **→ 0** |

Unhook timing measured in a real FPM request (vendored AS **3.9.0**):

| Stage | `has_action(WP_CRON_HOOK)` |
|---|---|
| `plugins_loaded:10` | false |
| `init:1` | false |
| `action_scheduler_init:10` | attached, priority 10 |
| `init:99` | attached |
| `init:101` | false (removed at `init:100`) |
| `wp_loaded:10` | false |

Canary output when the admin-ajax path was open:
`[as-guard] queue run outside CLI: sapi=fpm-fcgi uri=/wp/wp-admin/admin-ajax.php ip=::1`

**Traps that each produce a convincing false result:** empty queue; no callback attached (AS completes actions as instant no-ops — count drops, nothing logs); `doing_cron` transient set (both `wp-cron.php` and `--due-now` become silent no-ops); supervisor still running; stale rows in `wp_actionscheduler_claims` (blocks `run()` entirely); wrong URL when core isn't at the web root; `error_log()` not writable (silent fallback to stderr); unrelated fatal earlier in the run; judging by status code. Always run the control: after proving the web path is blocked, prove the CLI runner still drains.

---

## 5. PoC design constraints (learned the hard way)

- **PHP-FPM is mandatory.** `fastcgi_finish_request()` does not exist under the PHP built-in server or CLI, so scenario A **cannot** be reproduced with `wp server` or a light/SQLite setup. Needs nginx (or Caddy) + PHP-FPM + MySQL/MariaDB.
- **Action Scheduler can be the standalone plugin** — nothing in the findings depends on it being vendored inside WP Rocket. Only the *path* differs. Pin **3.9.0** to match, and re-verify the `init` timing table if a different version is used.
- **Core must be at the web root or not** — irrelevant to the findings, but if you mirror the Bedrock-ish layout, remember `site_url('wp-cron.php')` resolves to `/wp/wp-cron.php`.
- **Don't reproduce scenario B with S3.** Any plugin that throws an uncaught exception on an early cron event will do; a deliberate `add_action('init', ...)`-scheduled throwing event is cleaner and dependency-free.
- Keep it a **separate repo/folder**, not inside `criacao.cc` — the request was explicitly for an isolated PoC.
- A `docker-compose.yml` + `Makefile` with targets like `make up`, `make arm`, `make attack-cron`, `make attack-ajax`, `make cli-drain`, `make report` would make the scenarios one-command reproducible for readers of the post.

---

## 6. State of the `criacao.cc` working environment (read before touching it)

The previous session had to build the Docker environment from scratch; the checkout was configured for light mode. Current state:

- **The repo-root `.env` is now Docker-mode.** Light mode will not boot until it is swapped back. The two modes cannot share one file (`create-site` exports the root `.env`; `Dotenv::createImmutable` in `wordpress/wp-config.php` then skips both `.env` files, so light-mode `DB_*` dummies silently win).
- **Backup of the original light-mode `.env`:** `$TMPDIR/criacao-cc.env.light.backup` (mode 600). **Contains a Repman token and an Elementor licence key — do not commit, paste, or upload it.**
- **Local test site created:** slug `cronlabca5aqi8f` at `https://cronlab.criacao.local` (throwaway local admin credentials `admin` / `admin`). Files in `sites/` and `sites_data/`, database of the same name. The user has **not** yet decided whether to tear it down — ask.
- **Also created:** `environment/infra/resources/server/etc/ssl/dhparam.pem`, MinIO bucket `sites-static-files`.
- **All instrumentation removed** — probe mu-plugins deleted, probe rows deleted from `wp_actionscheduler_actions`, final file syntax-checked and re-verified end-to-end.
- **No cron supervisor was stopped or needs restarting** — the systemd unit is staging-only; nothing equivalent runs locally.
- Uncommitted before the session began and still so: `environment/infra/resources/bin/wp-cron-runner` (modified), plus untracked `environment/infra/sites.yml`, `.local-ca/`, `.rsync-auto.*`.

Four undocumented blockers were hit standing the stack up (missing `PHP_FPM_CONNECTION`; `dhparam.pem` auto-created as a directory; `setfacl` unsupported on macOS bind mounts; site `nginx.conf` generated before `SSL_VERIFY_CLIENT=off`). All are written up in the `criacao-cc-local-env-modes` memory file — worth folding into the infra docs, but that is out of scope for the next session unless asked.

---

## 7. Open questions for the user

1. Where should the PoC live — a new local folder, a new GitLab project on `greatcode.aztecweb.net`, or a public repo intended to accompany the post?
2. Publication target and language for the post — internal, company blog, or personal; English or pt-BR? (Prior domain records in this project are pt-BR; the conversation was in English.)
3. Tear down the local `cronlabca5aqi8f` site and restore the light-mode `.env`, or leave the Docker environment in place?

---

## 8. Suggested skills

- **`prototype`** — the PoC is explicitly a throwaway artifact built to answer/demonstrate a question; this is its exact use case. Invoke it for the PoC half of the work.
- **`artifact-design`** *(required before publishing)* — if the post or the scenario results are rendered as an Artifact, load this first, per its own instruction.
- **`dataviz`** — only if the post gains charts (e.g. worker occupancy over time, drain curves). Must be read *before* writing any chart code.
- **`bash-defensive-patterns`** — if the PoC ships driver scripts (`make attack-cron` etc.), for `set -euo pipefail`-grade robustness.
- **`domain-modeling`** / **`issue-to-notion`** — only if the work is to be tracked as issues or mapped into the Notion project (`Criação .cc`, `6b8c37d4-d791-4852-a059-108bb6ae3211`); not needed for a standalone post + PoC.

Skip `tdd`, `code-quality`, `code-review` unless the PoC grows real application code — it is demonstration scaffolding, not production.

---

## 9. Standing instruction to carry forward

The user is working on their English and wants **every** prompt message corrected, with the explanation given in the conversation **and** appended to
`~/.config/ccsw/configs/aztec/projects/-Users-pittol-Sites-criacao-cc/memory/english-mistakes.md`.

Most recent correction (2026-07-27), from the arguments to this handoff:
"create **a** isolated POC" → "create **an** isolated PoC" — the article is *an* before a vowel sound (*an isolated*); also *PoC* is the conventional casing for proof of concept.

The single most recurring pattern in that log is **missing subject–auxiliary inversion in direct questions** ("What means X?" → "What does X mean?"). Second most common: Portuguese preposition calques (*work in* → *work on*, *summarize to me* → *summarize for me*).
