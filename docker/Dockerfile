# --- Single place the PHP version is pinned. ------------------------------
# Same discipline this repo already applied to ACTION_SCHEDULER_VERSION
# (that version now comes from docker/composer.lock instead -- see the
# Composer RUN step below): one ARG default, and docker-compose.yml
# deliberately does not pass a build `args:` override for it, so there is
# exactly one place this image's PHP version can come from. nginx's version
# gets the equivalent treatment as a single pinned `image:` tag on the
# `nginx` service in docker-compose.yml -- there is no Dockerfile for that
# image, so that one tag is its only source of truth.
ARG PHP_VERSION=8.3-fpm-bookworm
FROM php:${PHP_VERSION}

LABEL org.opencontainers.image.description="WordPress (nginx + PHP-FPM + SQLite) with Action Scheduler, both pinned via docker/composer.lock"

# WP-CLI's and Composer's extractors need more than PHP's stock 128M
# default to unpack the WordPress core and plugin archives.
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Issue #6's canary guard (docker/mu-plugins-available/30-log-non-cli-canary.php)
# writes to PHP's error log. Left at its default, PHP's `error_log` ini
# directive is empty, which under PHP-FPM means every error_log() call
# would go wherever the FPM pool happens to send stderr -- not necessarily
# a file this repo's tooling can read back to confirm the canary actually
# fired. Pointing `error_log` at a concrete, writable path here means the
# canary's destination is a known file both the serving process (php-fpm)
# and any `wp` CLI invocation (same php.ini/conf.d) write to and can be
# read back from -- and, critically, something
# docker/wp-cli/measure-async-ajax.php can *verify* is actually being
# written to before trusting its silence or its content, rather than
# assuming a destination that might quietly be going nowhere readable.
#
# Issue #7's admin-page-load vector uses the same destination the same
# way; its manual-run vector reuses it to confirm the canary genuinely
# does NOT fire on that path -- a documented blind spot, not an
# assumption. Both of #7's measure-*.php scripts verify this file is
# actually writable before trusting its silence or its content, same
# discipline as measure-async-ajax.php above.
RUN mkdir -p /var/log/wpcas \
    && touch /var/log/wpcas/php-error.log \
    && printf 'log_errors = On\nerror_log = /var/log/wpcas/php-error.log\n' > /usr/local/etc/php/conf.d/error-log.ini

# --- Issue #30: pin the worker pool and every relevant timeout ------------
#
# The base image loads no php.ini at all (see the memory_limit/error_log
# overrides above -- both exist for the same reason: without an explicit
# ini file, this stack would be silently running on whatever the compiled-in
# defaults happen to be). PHP-FPM's pool defaults matter here more than most:
# `pm = dynamic` with `pm.max_children = 5` is a different worker count than
# the 6-worker pool the retired php-cli-server model used (see
# docs/blog-post-draft.md's PHP_CLI_SERVER_WORKERS=6), which would make any
# later occupancy comparison against that retired model silently compare two
# differently-sized pools. Pinned to a *static* pool of 6 workers instead --
# matching that retired count exactly, and static (not dynamic) so "6" is
# the actual, unconditional number of workers this pool ever runs, not a
# ceiling three other pm.* directives could keep it under.
#
# request_terminate_timeout (PHP-FPM's own hard kill for a worker that has
# run longer than this many seconds, independent of PHP's own
# max_execution_time below) is left commented-out/disabled (0) by the base
# image -- as unbounded as the retired built-in server's own request handling
# was. Pinned explicitly here rather than left at that implicit "still
# unbounded" default.
#
# Named with a 'zz-' prefix (rather than editing /usr/local/etc/php-fpm.d/
# www.conf in place) so this file sorts, and therefore loads, after both
# the base image's own `docker.conf` and `www.conf` in php-fpm.d's default
# `include=*.conf` glob -- FPM merges same-named `[www]` pool sections
# across included files rather than erroring on the repeat (the base image's
# own docker.conf already relies on this same merge behaviour to set
# `listen`, a directive www.conf itself never sets), so this file's two
# directives are guaranteed to win over either base file's own values for
# the same pool. docker/wp-cli/lib/probe.php reads this exact file back
# (never a second, separately-maintained copy of "6"/"35") to carry both
# values into every preflight snapshot and therefore every result record.
RUN printf '[www]\npm = static\npm.max_children = 6\nrequest_terminate_timeout = 35\n' \
        > /usr/local/etc/php-fpm.d/zz-wpcas-pool.conf

# PHP's own execution-time ceiling. Left unset, PHP-FPM's request-handling
# workers sit at the compiled-in default of 30 seconds -- an execution
# ceiling that did not exist under the retired built-in server, which
# inherited an *unlimited* one (php's cli-server SAPI overrides
# max_execution_time to 0; see docker/wp-cli/lib/server-config.php's module
# docblock for the equivalent override this same base image's `php` CLI
# binary applies). That is a real behaviour change this
# server-model swap (issue #27/#29) already introduced, not something this
# line changes -- but it was, until now, an implicit one nowhere a reader
# could find it stated. Pinning it here at that same 30-second figure
# changes nothing about how the stack actually behaves; it only turns an
# already-latent ceiling into a known, explicit, and (via
# docker/wp-cli/lib/probe.php) recorded quantity.
#
# `ini_get('max_execution_time')` cannot read this back, despite being a
# genuine php.ini directive: PHP's CLI SAPI -- what every `wp eval-file`
# invocation runs as, preflight included -- unconditionally resets
# max_execution_time to 0 after ini parsing, regardless of what this file
# sets it to (confirmed directly against this same base image while building
# this). docker/wp-cli/lib/server-config.php's module docblock has the full
# story; docker/wp-cli/lib/probe.php reads this file's own bytes back
# instead, for exactly that reason.
RUN printf 'max_execution_time = 30\n' > /usr/local/etc/php/conf.d/execution-time.ini

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

# Composer itself -- only needed to run `composer install` below at image
# build time; nothing in the running container invokes it afterwards.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- The webroot, provisioned by Composer (issue #29 / ADR-0001) ---------
#
# Replaces the old `wp core download` + hand-rolled Action Scheduler zip
# download with a single `composer install` run against a committed
# lockfile (docker/composer.lock) -- closing the gap the README used to
# admit ("[WordPress is] not pinned, and not recorded in any committed
# result"). See docker/composer.json for the package list:
#
#   - johnpbloch/wordpress-core (+ its installer plugin), placed at ./wp
#     via the `wordpress-install-dir` extra below -- full core, including
#     its own default wp-content, exactly like `wp core download` used to
#     produce, just relocated under a `/wp` prefix instead of living at
#     the document root.
#   - wpackagist-plugin/action-scheduler, pinned to 4.0.0 (the same
#     version this image pinned before, now sourced from WordPress.org via
#     WPackagist instead of the project's GitHub release asset).
#   - wpackagist-plugin/sqlite-database-integration.
#
# Both plugins install to wp/wp-content/plugins/ (not the project-root
# wp-content/ composer/installers would use by default) via the
# `installer-paths` override in docker/composer.json -- content stays
# exactly where a plain, non-Composer WordPress install would put it,
# nested inside the core directory alongside wp-admin/wp-includes. This is
# deliberately not Bedrock: nothing here renames wp-content or moves
# configuration above the document root (see docs/adr/0001's "Layout"
# section for the full rationale and the rejected Bedrock alternative).
#
# COMPOSER_ALLOW_SUPERUSER=1: this build runs as root (like every other
# step in this image); Composer's own root check is a safety guard for
# interactive/shared-host use that doesn't apply to a disposable build
# stage.
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader

# SQLite Database Integration: place its db.php drop-in directly. The
# drop-in's own fallback resolves the plugin folder from its own location
# (wp-content/db.php -> wp-content/plugins/sqlite-database-integration), so
# no placeholder rewriting is needed. This is what makes wpdb talk to
# SQLite instead of the dummy MySQL credentials below, from the very first
# request onward -- no runtime GET/nonce admin flow required, which
# wouldn't be reachable headlessly anyway. README deviation 3 (SQLite, not
# MySQL) is otherwise untouched by this ticket.
RUN mkdir -p wp/wp-content/database \
    && cp wp/wp-content/plugins/sqlite-database-integration/db.copy wp/wp-content/db.php

# wp-config.php: dummy DB_* constants (unused once the SQLite drop-in takes
# over), a site URL derived at runtime from the port this checkout was
# assigned (so the same image works for any namespaced instance), and
# WP_SQLITE_AST_DRIVER. That last one opts into the plugin's newer
# grammar-based MySQL-on-SQLite translator instead of its legacy
# regex-based one: Action Scheduler's claim query is an
# `UPDATE t1 JOIN (subquery) t2 ... SET ...`, which the legacy translator
# cannot rewrite for SQLite (`near "t1": syntax error`) but the AST driver
# handles correctly. This is exactly the translation-layer risk issue #1
# settled -- see that ticket's "one scheduled action runs to completion"
# verification.
#
# Residual risk: the AST driver is the plugin's newer, less battle-tested
# translator (as opposed to the legacy regex-based one it defaults to).
# Every later scenario built on this stack now rides on that translator's
# fidelity for whatever queries Action Scheduler issues -- a future
# "zero drained" result should not be read as settled without first ruling
# out an AST-driver translation gap, the same way issue #1 ruled out the
# legacy translator's JOIN-UPDATE gap.
#
# WP_SITEURL carries the `/wp` prefix core now lives under; WP_HOME does
# not, so the site itself still resolves at the document root (issue #29
# acceptance criterion). --path=wp below writes this file to wp/wp-config.php
# first; the RUN step immediately after moves it up one directory -- the
# same "wp-config.php lives one level above ABSPATH" arrangement WordPress
# itself has supported since the "giving WordPress its own directory"
# technique, and which core's own wp-load.php (and WP-CLI's own config
# locator) already knows how to find without any further configuration.
#
# DISABLE_WP_CRON: added for issue #2. Action Scheduler registers its own
# fallback queue runner as a WP-Cron event (`action_scheduler_run_queue`,
# every minute) on top of its independent async-request dispatcher. With
# WP-Cron left on, core's own per-bootstrap wp_cron() check -- which runs
# on practically every request *and* WP-CLI invocation, not just page
# loads -- kept re-arming the "doing_cron" lock transient because that
# queue-runner event is essentially always due. This stack never relies on
# WP-Cron's spawn/loopback path in the first place: issue #1's own
# verification recipe drains the queue with the explicit
# `wp action-scheduler run`, and issue #2's seed/reset/preflight tooling
# does the same. Turning off the implicit spawn removes the self-inflicted
# churn without touching either of those explicit, working paths, or
# Action Scheduler's separate async-request dispatcher (unaffected by this
# constant).
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
# Treats an empty-string env value the same as an absent one (defaults to
# true) rather than handing '' to filter_var() (which reads it as an
# invalid/false boolean) -- harmless in practice given
# `set-disable-wp-cron` only ever writes the literal strings 'true'/'false'
# and docker-compose.yml's own `${WPCAS_DISABLE_WP_CRON:-true}` default,
# but an explicit check reads clearer than relying on that.
# WP-CLI churn guard (issue #5, found while measuring the fleet-caveat
# scenario, not present in issue #2's original DISABLE_WP_CRON=true
# default because it masked this entirely): WordPress's own per-bootstrap
# wp_cron() -> spawn_cron() check runs on every request AND every WP-CLI
# invocation alike (same mechanism the DISABLE_WP_CRON docblock above
# already flags), and it is gated on DISABLE_WP_CRON, not on SAPI. With
# that constant false (required for this scenario), every `wp eval-file`
# this repo's own tooling runs (seed/reset/preflight/measure*) re-triggers
# a spawn attempt on its own bootstrap, which risks leaving a stuck
# "doing_cron" lock that fails the *next* command's preflight, or --
# worse -- eventually *does* complete in the background and silently
# drains the seeded queue outside of any controlled measurement. Neither
# is what issue #5 is measuring: the vector under test is one explicit,
# external, unauthenticated GET, not this stack's own tooling accidentally
# triggering WP-Cron via its incidental bootstrap. `DOING_CRON` is
# spawn_cron()'s own unconditional early exit
# (`if ( defined('DOING_CRON') ... ) return false;`, independent of
# DISABLE_WP_CRON) -- defining it true for every WP-CLI process (WP_CLI is
# WP-CLI's own always-on constant, defined before wp-config.php loads)
# suppresses exactly that self-spawn, for every command, regardless of
# DISABLE_WP_CRON's value. It does not affect this guard's own condition
# (guard 1 also requires PHP_SAPI !== 'cli', which every WP-CLI process
# already satisfies) or any of this repo's existing, already-verified CLI
# controls, which never depended on this constant.
RUN wp config create --allow-root \
        --path=wp \
        --dbname=wordpress \
        --dbuser=wordpress \
        --dbpass=wordpress \
        --dbhost=localhost \
        --skip-check \
        --extra-php="define( 'WP_HOME', 'http://localhost:' . getenv( 'STACK_PORT' ) ); define( 'WP_SITEURL', 'http://localhost:' . getenv( 'STACK_PORT' ) . '/wp' ); define( 'WP_SQLITE_AST_DRIVER', true ); define( 'DISABLE_WP_CRON', ( false === getenv( 'WPCAS_DISABLE_WP_CRON' ) || '' === getenv( 'WPCAS_DISABLE_WP_CRON' ) ) ? true : filter_var( getenv( 'WPCAS_DISABLE_WP_CRON' ), FILTER_VALIDATE_BOOLEAN ) ); if ( defined( 'WP_CLI' ) && WP_CLI ) { define( 'DOING_CRON', true ); }" \
    && mv wp/wp-config.php wp-config.php

RUN mkdir -p wp/wp-content/mu-plugins
# docker-compose.yml bind-mounts docker/mu-plugins/ over this path at
# runtime (so bin/guard can arm/disarm guard files without a rebuild), so
# this COPY is shadowed and has no effect when the image is run via
# `bin/stack`/`docker compose`. It only matters as a fallback for running
# this image directly (e.g. plain `docker run`, no compose, no bind
# mount) -- kept for that case, not because it does anything under the
# normal `bin/stack up` path.
COPY mu-plugins/wpcas-poc-probe.php /var/www/html/wp/wp-content/mu-plugins/wpcas-poc-probe.php
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Issue #2's seed/reset/preflight scripts, run via `wp eval-file`. Placed
# outside the web root deliberately: a .php file living under wp-content
# would otherwise be directly, remotely requestable over HTTP (nginx's
# `location ~ \.php$` block, docker/nginx/default.conf, passes any
# existing .php file straight to php-fpm, no allow-list). These scripts
# assume a WP-CLI bootstrap ($args, WP_CLI::*), not an HTTP request, so
# they live at /opt/wpcas-tools instead -- unaffected by the webroot's
# move to a Composer-provisioned /wp layout (issue #29 acceptance
# criterion: "the measurement tooling stays outside the document root").
COPY wp-cli/ /opt/wpcas-tools/

# php-fpm's default pool listens on 9000; not published to the host (only
# nginx's port is, via docker-compose.yml), but documented here the same
# way EXPOSE 8080 documented the old built-in server's port.
EXPOSE 9000

# Runtime working directory is core's own path, not the document root --
# `docker compose exec php-fpm wp ...` (bin/stack's seed/reset/preflight/
# measure* subcommands) rely on WP-CLI's own cwd-based lookup, and WP-CLI
# only finds wp-config.php one directory up from a *WordPress* path (the
# same rule entrypoint.sh's explicit --path=/var/www/html/wp already
# depends on); it does not search back down into a `wp/` subdirectory from
# the document root.
WORKDIR /var/www/html/wp

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
