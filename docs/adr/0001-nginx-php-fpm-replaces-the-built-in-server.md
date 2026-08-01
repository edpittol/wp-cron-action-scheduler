# nginx + PHP-FPM replaces PHP's built-in server

The finding this repo exists to demonstrate above all others — that a guard on the cron entry point cannot return an **observable status**, because core's `wp-cron.php` calls `fastcgi_finish_request()` before it loads mu-plugins — is structurally unreproducible under PHP's built-in server, since that function only exists under PHP-FPM. Rather than keep both server models, we replace `php -S` and its `try_files`-emulating router outright: nginx + PHP-FPM becomes the only **server model** the repo contains.

## Considered options

- **Coexisting, selectable server models.** Would have let one report show the same guard producing a real 403 under `cli-server` and a **post-flush status** under `fpm-fcgi` — the sharpest possible contrast, as committed data rather than prose. Rejected: two serving paths, two configurations, and a **server model** axis on every **scenario**, for a contrast that git history already preserves.
- **Coexisting, with PHP-FPM as the default.** Same machinery, same rejection.

## Consequences

- Every previously committed **result record** was measured under a **server model** the code no longer contains. They are deleted rather than kept, because nothing in the repo can reproduce them; git history is their archive. The full matrix is re-measured under PHP-FPM and that run becomes the only committed evidence.
- Two README deviations disappear: there is now a `fastcgi_finish_request()` call to mask the response, and the SAPI string is genuinely `fpm-fcgi`. The SQLite deviation is untouched — `fastcgi_finish_request()` is independent of the database, and swapping it in the same change would have moved two variables at once.
- **Occupancy** figures are relabelled to the new **server model**. The inference behind them stays valid, since an FPM child handles exactly one request at a time just as a built-in-server worker did, but its stated basis has to be rewritten to cite FPM rather than a server that is no longer present.

## Layout: core under `/wp`, provisioned by Composer, deliberately not Bedrock

The webroot moves from `wp core download` at the document root to a Composer install with core under `/wp` and content beside it. Two reasons: a `composer.lock` finally pins WordPress itself, closing a gap the README had to admit ("not pinned, and not recorded in any committed result"); and core under `/wp` reproduces the entry-point paths the original environment's own canary output recorded, so this repo's example log lines can match a reader's production ones.

Full Bedrock was considered and rejected: renaming the content directory and splitting configuration above the document root would churn every path reference in the repo and relocate the runtime cron-constant logic, while buying nothing for any finding under test. The fidelity worth having is the `/wp` prefix; the rest is convention.
