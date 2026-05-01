# 001 — Persistent sessions via `PdoSessionHandler`

**Status:** done
**Type:** infra / config
**Scope:** small (2-3 files + 1 migration)

## Problem

Sessions live in `var/cache/<env>/sessions` (Symfony's default native file handler). On deploy the container's `var/cache/` is wiped, so every user is logged out.

## Goal

Move session storage to Postgres via `Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler`, reusing the existing Doctrine connection. Sessions survive deploys and are long-lived (30 days idle).

## Context (current state)

- `config/packages/framework.php:10` — `'session' => true` (boolean shortcut → native file handler).
- `config/packages/test/framework.php` — overrides with `storage_factory_id: session.storage.factory.mock_file` for tests. **Must keep this override.**
- `compose.yaml` — Postgres 17 already in stack, single DB. `DATABASE_URL` configured in `.env`.
- `config/services.php` — uses PHP DI loader (`App::config([...])`), `_defaults: { autowire: true, autoconfigure: true, public: true }`.
- Migrations live in `migrations/`, classes named `VersionYYYYMMDDHHMMSS`. Use `bin/console make:migration` (per `CLAUDE.md`) but the dev should write the SQL by hand because Doctrine won't auto-detect a non-entity table.

## Requirements

### 1. Register `PdoSessionHandler` as a service

In `config/services.php` (under `services:`), add:

```php
\Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler::class => [
    'arguments' => [
        inline_service(\PDO::class)
            ->factory([service('doctrine.dbal.default_connection'), 'getNativeConnection']),
        ['db_table' => 'sessions'],
    ],
],
```

(Use whatever PHP-config helper syntax matches existing patterns in the file. Goal: pass the Doctrine connection's native `\PDO` so we don't open a second DB connection per request. Keep `lock_mode` at default `LOCK_TRANSACTIONAL`.)

### 2. Wire the handler + long lifetime in `config/packages/framework.php`

Replace `'session' => true` with:

```php
'session' => [
    'handler_id' => \Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler::class,
    'cookie_lifetime' => 2592000,   // 30 days
    'gc_maxlifetime' => 2592000,    // 30 days
    'cookie_secure' => 'auto',
    'cookie_samesite' => 'lax',
],
```

Do **not** touch `config/packages/test/framework.php` — its `storage_factory_id: session.storage.factory.mock_file` override means tests bypass the handler entirely. Verify nothing breaks by running the full test suite.

### 3. Doctrine migration for the `sessions` table

Run `docker compose exec web bin/console make:migration`, then replace the body with the Postgres schema expected by `PdoSessionHandler`:

```php
public function up(Schema $schema): void
{
    $this->addSql('CREATE TABLE sessions (
        sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
        sess_data BYTEA NOT NULL,
        sess_lifetime INTEGER NOT NULL,
        sess_time INTEGER NOT NULL
    )');
}

public function down(Schema $schema): void
{
    $this->addSql('DROP TABLE sessions');
}
```

Column types come from `PdoSessionHandler::getCreateTableSql()` for the `pgsql` driver — do not change them.

### 4. No env vars, no compose changes

Reuses the existing `DATABASE_URL`. Nothing to add in `.env`, `compose.yaml`, or deploy scripts.

## Acceptance

- `docker compose exec web composer quality` is green (cs, phpstan, tests).
- After `bin/console doctrine:migrations:migrate`, the `sessions` table exists in Postgres.
- Logging in writes a row to `sessions`; `sess_lifetime` ≈ `2592000`.
- Restarting / rebuilding the `web` container (simulating deploy) keeps existing logged-in users logged in — refresh the browser and the session is still valid.
- Existing test suite passes; tests still use file-based mock storage.
- `var/cache/<env>/sessions/` is no longer being written to.

## Out of scope

- Migrating any existing live sessions (they're already lost on every deploy — no value preserving them).
- Switching to Redis / Memcached / advisory locks. If we later see DB contention on `sessions`, revisit `lock_mode: PdoSessionHandler::LOCK_ADVISORY`.
- "Remember me" cookies — handled by Symfony Security separately, untouched.
- A periodic cleanup job for expired sessions. PHP's built-in GC (called probabilistically by `PdoSessionHandler::gc()`) handles this; the table will stay small at this scale.

## Open questions

None — proceed.

## References

- Symfony docs: <https://symfony.com/doc/current/session/database.html#store-sessions-in-a-database-with-pdosessionhandler>
- Schema: `vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php`, see `getCreateTableSql()`.
