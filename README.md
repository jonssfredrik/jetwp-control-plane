# JetWP Control Plane

Self-hosted management plane for fleets of WordPress sites. Pairs with the
[JetWP Agent](https://github.com/jonssfredrik/jeywp-agent) plugin installed on
each managed site.

The Control Plane:

- registers WordPress sites via one-time pairing tokens
- ingests heartbeats and telemetry from each Agent (HMAC-signed)
- queues, dispatches, and audits jobs (cache flush, plugin/core update,
  integrity check, DB optimize, ...)
- executes those jobs over SSH using WP-CLI through a Runner
- exposes a minimal dashboard for operators

## Stack

- PHP 8.2+
- MySQL 8 (PDO, no ORM)
- File-based SQL migrations
- Plain PHP routing in `public/index.php` (no framework)
- Session auth + CSRF for the dashboard

## Repository Layout

```
control-plane/
├── bootstrap.php           # autoloader, config, session, service wiring
├── cli.php                 # CLI entry point (migrations, users, servers, jobs)
├── queue-worker.php        # long-running worker that drains the jobs queue
├── config.example.php      # default config (env-overridable)
├── config.php              # local config (gitignored)
├── migrate.php             # convenience wrapper around `cli.php migrate`
├── migrations/             # versioned .sql files
├── public/
│   └── index.php           # web entry point (Apache/nginx document root)
├── src/
│   ├── Api/                # HTTP controllers (Agent + Dashboard JSON API)
│   ├── Auth/               # Session auth, CSRF, RBAC
│   ├── Config/             # Config loader
│   ├── Db/                 # PDO Connection + Migrator
│   ├── Jobs/               # Job factory + per-type validation
│   ├── Models/             # User, Server, Site, Telemetry, Job, PairingToken
│   ├── Queue/              # Worker + ConcurrencyManager
│   ├── Runner/             # SshClient, CommandBuilder, JobExecutor
│   ├── Security/           # Secrets (envelope encryption for HMAC keys)
│   ├── Services/           # ActivityLog, Alerts
│   └── Support/            # Misc helpers (Uuid)
└── templates/              # PHP view templates (login, sites, jobs, dashboard)
```

## Quick Start (local)

Requires PHP 8.2+, MySQL 8, and a writable working directory.

```bash
# 1. Configure
cp config.example.php config.php
# edit config.php OR set environment variables (see "Configuration" below)

# 2. Create the database and apply migrations
php cli.php migrate

# 3. Create your first admin user
php cli.php user:create admin admin@example.com --role=admin

# 4. Serve the public directory (any web server works)
php -S localhost:8080 -t public

# 5. In another shell, run the queue worker
php queue-worker.php
```

The dashboard is now available at <http://localhost:8080>.

## Configuration

All settings in `config.example.php` accept environment overrides. The most
important ones:

| Env var                                   | Default                       | Purpose                                |
| ----------------------------------------- | ----------------------------- | -------------------------------------- |
| `JETWP_BASE_URL`                          | `http://localhost:8080`       | Public URL Agents post heartbeats to   |
| `JETWP_DB_HOST` / `_PORT` / `_DATABASE`   | `127.0.0.1` / `3306` / `jetwp`| MySQL connection                       |
| `JETWP_DB_USERNAME` / `_PASSWORD`         | `root` / *(empty)*            | MySQL credentials                      |
| `JETWP_ENCRYPTION_KEY`                    | dev placeholder               | **Required in prod.** Encrypts HMAC secrets in DB |
| `JETWP_TIMEZONE`                          | `Europe/Stockholm`            | Default tz                             |
| `JETWP_QUEUE_POLL_INTERVAL`               | `5`                           | Worker idle sleep (seconds)            |
| `JETWP_QUEUE_DEFAULT_TIMEOUT`             | `300`                         | Per-job SSH timeout (seconds)          |
| `JETWP_ALERT_RECIPIENTS`                  | *(empty)*                     | Comma-separated alert recipients       |
| `JETWP_ALERT_HEARTBEAT_MISSED_MINUTES`    | `30`                          | Threshold for missed-heartbeat alerts  |

If you don't use environment variables, edit `config.php` directly — it is
gitignored.

## CLI Reference

```bash
php cli.php migrate                                 # apply pending migrations
php cli.php migrate:fresh                           # drop DB and re-migrate (DESTRUCTIVE)
php cli.php user:create <username> <email> [--role=admin|operator] [--password=...]
php cli.php server:add --label=... --hostname=... --ssh-user=... --ssh-key=... [--ssh-port=22] [--php-path=...] [--wp-cli=...]
php cli.php server:test --id=<server_id> [--timeout=10]
php cli.php token:create --server-id=<id> [--ttl-minutes=60]
php cli.php job:run --id=<job_uuid> [--dry-run] [--timeout=300]
php cli.php alerts:heartbeats [--threshold=30]
```

Pairing tokens are one-time use; share the token output of `token:create` with
the Agent at registration.

## Agent Pairing Flow

1. Operator runs `php cli.php server:add ...` to register a managed server
2. Operator runs `php cli.php token:create --server-id=<id>` and copies the token
3. Operator installs the JetWP Agent plugin on the WordPress site
4. In WP admin → **Settings → JetWP Agent**, paste the Control Plane URL and
   pairing token, then click **Register**
5. Agent calls `POST /api/v1/sites/register`; Control Plane verifies the token,
   creates the site row, and returns a freshly generated HMAC secret
6. Agent stores the secret encrypted and begins heartbeating every 15 minutes

## Agent API (HMAC-signed)

All endpoints below the `register` step require:

- `X-JetWP-Site-Id: <uuid>`
- `X-JetWP-Timestamp: <unix seconds>` (±60 s clock window)
- `X-JetWP-Signature: hmac_sha256(body + "|" + timestamp, secret)`

| Method | Path                                       | Purpose                       |
| ------ | ------------------------------------------ | ----------------------------- |
| GET    | `/api/v1/health`                           | Public health probe (no auth) |
| POST   | `/api/v1/sites/register`                   | One-time pairing exchange     |
| POST   | `/api/v1/sites/{id}/heartbeat`             | Telemetry ingest              |
| GET    | `/api/v1/sites/{id}/jobs/pending`          | Pull pending jobs             |
| POST   | `/api/v1/sites/{id}/job-result`            | Push job result back          |

## Job Types (MVP)

All MVP job types compile to safe WP-CLI commands and are executed by the
Runner over SSH:

- `uptime.check`
- `cache.flush`
- `plugin.update` (requires `params.slug`)
- `plugin.update_all`
- `core.update` (optional `params.version`)
- `security.integrity` (`wp core verify-checksums --format=json`)
- `db.optimize`
- `translations.update`

Operator role can create all of the above. The admin-only `custom.wp_cli`
type (free-form WP-CLI) is gated by `Authorization::ensureJobTypeAllowed`.

## Security Notes

- HMAC secrets are encrypted at rest with `JETWP_ENCRYPTION_KEY` (envelope
  encryption in `src/Security/Secrets.php`)
- All dashboard POST routes require valid CSRF tokens
- Bcrypt cost 12 for user passwords
- Failed-login attempt counter on `users` table
- `activity_log` records auth events, job creation, agent requests, and
  alert delivery

## Development Status

MVP-complete: site registration, telemetry, jobs CRUD, queue worker, SSH
runner, dashboard UI, RBAC, CSRF, basic alerts. End-to-end pilot validation
against ≥4 live sites is the remaining bar before declaring MVP done.

See the planning docs in the parent project repository
(`01-PROJECT-OVERVIEW.md` … `16-MVP-DEVELOPER-TODO.md`) for the full spec.

## License

Proprietary / private project. No license granted unless explicitly noted.
