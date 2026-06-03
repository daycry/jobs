# Installation

## Requirements

- PHP `^8.2`
- CodeIgniter 4
- Composer

## Install

```bash
composer require daycry/jobs
```

## Migrations

The package ships migrations for the queue table, its lease columns and the history table. Run them with the package namespace:

```bash
php spark migrate -n "Daycry\Jobs"
```

> The `database` backend requires these tables. The `sync` backend (the zero-config default) does not, but running the migrations is recommended so you can switch backends later. The table name is configurable via `Config\Jobs::$database['table']` and the connection group via `Config\Jobs::$databaseGroup` / `$database['group']`.

## Optional Extensions

Install only what the backend you use needs:

| Backend | Requirement |
|---------|-------------|
| `redis` | `ext-redis` |
| `beanstalk` | `pda/pheanstalk` (`composer require pda/pheanstalk`) |
| `serviceBus` | none (uses the CodeIgniter `curlrequest` service) |

For real, interrupting timeouts and graceful worker shutdown on Unix, install `ext-pcntl`. Without it, timeouts fall back to a documented soft mode.

## Optional: Publish the Config

Customise the package configuration under your application namespace:

```bash
php spark jobs:publish
```

This creates `app/Config/Jobs.php` extending `Daycry\Jobs\Config\Jobs`. Publishing is optional — the package ships with working defaults (the `sync` backend, all five handlers registered). Edit it to set your `queues`, the default `worker`, retry/backoff parameters, the per-queue handler allowlist, signing key and backend-specific settings. See [Configuration](CONFIGURATION.md).

## Next Steps

- [Defining Schedules](scheduling.md)
- [Queues & Backends](QUEUES.md)
- [CLI Commands](COMMANDS.md)
