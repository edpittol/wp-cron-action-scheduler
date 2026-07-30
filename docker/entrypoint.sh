#!/usr/bin/env bash
set -euo pipefail

WP_PATH=/var/www/html
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
    echo "[entrypoint] Activating Action Scheduler ${ACTION_SCHEDULER_VERSION:-}..."
    wp plugin activate action-scheduler --allow-root --path="$WP_PATH"
else
    echo "[entrypoint] Action Scheduler already active."
fi

echo "[entrypoint] WordPress ready at http://localhost:${PORT}/"
echo "[entrypoint] Serving with PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-1} on 0.0.0.0:${PORT}"
exec php -S "0.0.0.0:${PORT}" -t "$WP_PATH" "$WP_PATH/router.php"
