#!/usr/bin/env bash
set -euo pipefail

# Core lives under /wp (issue #29); wp-config.php sits one directory above
# it (see docker/Dockerfile's `wp config create` step), which is exactly
# what --path below needs to point at -- WP-CLI locates wp-config.php the
# same way core's own wp-load.php does, checking --path first and then one
# directory up.
WP_PATH=/var/www/html/wp
PORT="${STACK_PORT:-8080}"

cd "$WP_PATH"

if ! wp core is-installed --allow-root --path="$WP_PATH" >/dev/null 2>&1; then
    echo "[entrypoint] Installing WordPress (SQLite)..."
    wp core install \
        --allow-root \
        --path="$WP_PATH" \
        --url="http://localhost:${PORT}" \
        --title="WP Cron / Action Scheduler PoC" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.test \
        --skip-email
else
    echo "[entrypoint] WordPress already installed, skipping core install."
fi

if ! wp plugin is-active action-scheduler --allow-root --path="$WP_PATH" >/dev/null 2>&1; then
    echo "[entrypoint] Activating Action Scheduler..."
    wp plugin activate action-scheduler --allow-root --path="$WP_PATH"
else
    echo "[entrypoint] Action Scheduler already active."
fi

# The steps above run as root (this entrypoint's own user, via
# --allow-root), so the SQLite data file `wp core install` just created --
# or that a previous container already created in the wp-database named
# volume -- is root-owned. php-fpm's default pool (docker/Dockerfile pins
# no override) runs its actual request-handling workers as www-data, which
# cannot write to a root-owned directory or file. Re-asserted on every
# start (not just image build time) so it holds regardless of whether
# wp-content/database came from a fresh named volume or one populated by
# an earlier run.
chown -R www-data:www-data "$WP_PATH/wp-content/database"

echo "[entrypoint] WordPress ready at http://localhost:${PORT}/ (core served from /wp)"
echo "[entrypoint] Starting PHP-FPM..."
exec php-fpm --nodaemonize
