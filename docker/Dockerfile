FROM php:8.3-cli-bookworm

# --- Single place the Action Scheduler version is pinned. -----------------
# Every other reference in this image (the download URL, the label below)
# is derived from this one build arg default. Acceptance criterion:
# "Action Scheduler 4.0.0 is active, with its version pinned in exactly one
# place." docker-compose.yml deliberately does NOT pass a build `args:`
# value for this -- doing so would create a second source of truth that
# could silently diverge from this default.
#
# Caveat: wp-content/database (the actual SQLite data file) is a named
# volume so it survives `bin/stack down` / `up`, but everything else under
# wp-content -- including this plugin directory -- is baked into the image
# and is NOT volume-shadowed. That means a rebuild after bumping this ARG
# does take effect on the next `up`, with no volume wipe required.
ARG ACTION_SCHEDULER_VERSION=4.0.0
ENV ACTION_SCHEDULER_VERSION=${ACTION_SCHEDULER_VERSION}
LABEL org.opencontainers.image.description="WordPress (PHP built-in server + SQLite) with Action Scheduler ${ACTION_SCHEDULER_VERSION}"

# WP-CLI's extractor needs more than PHP's stock 128M default to unpack the
# WordPress core tarball.
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        unzip \
        ca-certificates \
        libsqlite3-dev \
        less \
    && docker-php-ext-install pdo_sqlite \
    && apt-get purge -y --auto-remove libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# WP-CLI
RUN curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && wp cli version --allow-root

WORKDIR /var/www/html

# WordPress core.
RUN wp core download --allow-root --locale=en_US

# wp-config.php: dummy DB_* constants (unused once the SQLite drop-in takes
# over), a site URL derived at runtime from the port this checkout was
# assigned (so the same image works for any namespaced instance), and
# WP_SQLITE_AST_DRIVER. That last one opts into the plugin's newer
# grammar-based MySQL-on-SQLite translator instead of its legacy
# regex-based one: Action Scheduler's claim query is an
# `UPDATE t1 JOIN (subquery) t2 ... SET ...`, which the legacy translator
# cannot rewrite for SQLite (`near "t1": syntax error`) but the AST driver
# handles correctly. This is exactly the translation-layer risk this ticket
# exists to settle -- see the "one scheduled action runs to completion"
# verification below.
#
# Residual risk: the AST driver is the plugin's newer, less battle-tested
# translator (as opposed to the legacy regex-based one it defaults to).
# Every later scenario built on this stack now rides on that translator's
# fidelity for whatever queries Action Scheduler issues -- a future
# "zero drained" result should not be read as settled without first ruling
# out an AST-driver translation gap, the same way this ticket ruled out the
# legacy translator's JOIN-UPDATE gap.
#
# DISABLE_WP_CRON: added for issue #2. Action Scheduler registers its own
# fallback queue runner as a WP-Cron event (`action_scheduler_run_queue`,
# every minute) on top of its independent async-request dispatcher. With
# WP-Cron left on, core's own per-bootstrap wp_cron() check -- which runs
# on practically every request *and* WP-CLI invocation, not just page
# loads -- kept re-arming the "doing_cron" lock transient because that
# queue-runner event is essentially always due. The built-in PHP server's
# own async loopback to wp-cron.php doesn't reliably complete (no real
# FPM/persistent worker pool backing it here), so the lock routinely
# outlived its own trigger, sitting there for its full timeout even right
# after `bin/stack reset` had just cleared it -- exactly the false
# "cron-in-progress" state issue #2's preflight exists to catch, except
# self-inflicted by WordPress's own default cron wiring rather than a
# leftover from a previous run. This stack never relies on WP-Cron's
# spawn/loopback path in the first place: issue #1's own verification
# recipe drains the queue with the explicit `wp action-scheduler run`,
# and issue #2's seed/reset/preflight tooling does the same. Turning off
# the implicit spawn removes the self-inflicted churn without touching
# either of those explicit, working paths, or Action Scheduler's separate
# async-request dispatcher (unaffected by this constant).
#
# Runtime-switchable (issue #5): the value below is no longer the literal
# `true` issue #2 baked in -- it reads the WPCAS_DISABLE_WP_CRON env var
# via getenv() on every request, defaulting to true when that var is
# absent (e.g. this image run directly with plain `docker run`, no
# compose). docker-compose.yml passes WPCAS_DISABLE_WP_CRON through from
# the host (see bin/stack's `set-disable-wp-cron` subcommand), so flipping
# it only requires recreating the container with a new env value -- no
# image rebuild. This is the "fleet caveat" scenario issue #5 exists to
# measure: guard section 1 (block HTTP requests to the cron entry point)
# only triggers while DISABLE_WP_CRON is true, so a site that legitimately
# depends on the loopback (this constant false) must not lose cron
# silently just because a shared mu-plugins directory armed that guard.
# WP-CLI churn guard (issue #5, found while measuring the fleet-caveat
# scenario, not present in issue #2's original DISABLE_WP_CRON=true
# default because it masked this entirely): WordPress's own per-bootstrap
# wp_cron() -> spawn_cron() check runs on every request AND every WP-CLI
# invocation alike (same mechanism the DISABLE_WP_CRON docblock above
# already flags), and it is gated on DISABLE_WP_CRON, not on SAPI. With
# that constant false (required for this scenario), every `wp eval-file`
# this repo's own tooling runs (seed/reset/preflight/measure*) re-triggers
# a spawn attempt on its own bootstrap -- which, on this stack's PHP
# built-in server (no persistent worker pool), does not reliably
# complete, leaving a stuck "doing_cron" lock that fails the *next*
# command's preflight, or -- worse -- eventually *does* complete in the
# background and silently drains the seeded queue outside of any
# controlled measurement. Neither is what issue #5 is measuring: the
# vector under test is one explicit, external, unauthenticated GET, not
# this stack's own tooling accidentally triggering WP-Cron via its
# incidental bootstrap. `DOING_CRON` is spawn_cron()'s own unconditional
# early exit (`if ( defined('DOING_CRON') ... ) return false;`,
# independent of DISABLE_WP_CRON) -- defining it true for every WP-CLI
# process (WP_CLI is WP-CLI's own always-on constant, defined before
# wp-config.php loads) suppresses exactly that self-spawn, for every
# command, regardless of DISABLE_WP_CRON's value. It does not affect this
# guard's own condition (guard 1 also requires PHP_SAPI !== 'cli', which
# every WP-CLI process already satisfies) or any of this repo's existing,
# already-verified CLI controls, which never depended on this constant.
RUN wp config create --allow-root \
        --dbname=wordpress \
        --dbuser=wordpress \
        --dbpass=wordpress \
        --dbhost=localhost \
        --skip-check \
        --extra-php="define( 'WP_HOME', 'http://localhost:' . getenv( 'STACK_PORT' ) ); define( 'WP_SITEURL', 'http://localhost:' . getenv( 'STACK_PORT' ) ); define( 'WP_SQLITE_AST_DRIVER', true ); define( 'DISABLE_WP_CRON', false !== getenv( 'WPCAS_DISABLE_WP_CRON' ) ? filter_var( getenv( 'WPCAS_DISABLE_WP_CRON' ), FILTER_VALIDATE_BOOLEAN ) : true ); if ( defined( 'WP_CLI' ) && WP_CLI ) { define( 'DOING_CRON', true ); }"

# SQLite Database Integration: install the plugin, then place its db.php
# drop-in directly. The drop-in's own fallback resolves the plugin folder
# from its own location (wp-content/db.php -> wp-content/plugins/
# sqlite-database-integration), so no placeholder rewriting is needed. This
# is what makes wpdb talk to SQLite instead of the dummy MySQL credentials
# above, from the very first request onward -- no runtime GET/nonce admin
# flow required, which wouldn't be reachable headlessly anyway.
RUN mkdir -p wp-content/plugins wp-content/database \
    && curl -fsSL -o /tmp/sqlite-database-integration.zip \
        https://downloads.wordpress.org/plugin/sqlite-database-integration.zip \
    && unzip -q /tmp/sqlite-database-integration.zip -d wp-content/plugins \
    && rm /tmp/sqlite-database-integration.zip \
    && cp wp-content/plugins/sqlite-database-integration/db.copy wp-content/db.php

# Action Scheduler, pinned via the ARG above and installed straight from the
# project's own GitHub release asset (also distributed on WordPress.org, but
# the GitHub release is the canonical, verifiable source for a tagged
# version number).
RUN curl -fsSL -o /tmp/action-scheduler.zip \
        "https://github.com/woocommerce/action-scheduler/releases/download/${ACTION_SCHEDULER_VERSION}/action-scheduler.zip" \
    && unzip -q /tmp/action-scheduler.zip -d wp-content/plugins \
    && rm /tmp/action-scheduler.zip

COPY router.php /var/www/html/router.php
RUN mkdir -p wp-content/mu-plugins
# docker-compose.yml bind-mounts docker/mu-plugins/ over this path at
# runtime (so bin/guard can arm/disarm guard files without a rebuild), so
# this COPY is shadowed and has no effect when the image is run via
# `bin/stack`/`docker compose`. It only matters as a fallback for running
# this image directly (e.g. plain `docker run`, no compose, no bind
# mount) -- kept for that case, not because it does anything under the
# normal `bin/stack up` path.
COPY mu-plugins/wpcas-poc-probe.php /var/www/html/wp-content/mu-plugins/wpcas-poc-probe.php
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Issue #2's seed/reset/preflight scripts, run via `wp eval-file`. Placed
# outside the web root deliberately: router.php's try_files semantics hand
# any *existing file* under /var/www/html straight to the built-in server
# to execute in place (see its own docstring), so a .php file living under
# wp-content would be directly, remotely requestable over HTTP. These
# scripts assume a WP-CLI bootstrap ($args, WP_CLI::*), not an HTTP
# request, so they live at /opt/wpcas-tools instead.
COPY wp-cli/ /opt/wpcas-tools/

# Enough php-cli-server workers that a loopback request (e.g. the async
# Action Scheduler dispatcher, or wp-cron.php's own admin-ajax loopback)
# can be served by a second worker instead of deadlocking against the one
# worker that is still waiting on its own response.
ENV PHP_CLI_SERVER_WORKERS=6

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
