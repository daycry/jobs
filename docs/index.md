# Codeigniter Jobs Documentation

Job scheduling and queue processing for CodeIgniter 4. Define work with a fluent, immutable builder, dispatch it to one of five queue backends, and process it with a resilient worker featuring real timeouts, retries with backoff, signed envelopes, idempotency and per-queue handler allowlists.

> **v3.0** is a single, clean architecture. The legacy mutable `Job` builder, the V1 `Scheduler`, the performance loggers and the `QueueManager` were removed. Upgrading from v1? See [Migration v1 → v3](MIGRATION-v1-to-v3.md).

---

## Quick Start

```php
use Daycry\Jobs\Jobs;

$id = Jobs::define('command', 'app:report')
    ->named('daily-report')
    ->dailyAt('02:00')
    ->queue('reports')
    ->maxRetries(3)
    ->dispatch();
```

`Jobs::define()` opens a fluent `JobBuilder`; `dispatch()` enqueues the resulting immutable `JobDefinition` onto the configured backend and returns its id. Use `toDefinition()` to build a definition without enqueuing it, and `Jobs::backend(?string $name)` to resolve a backend directly.

---

## Documentation Map

| Page | Description |
|------|-------------|
| [Installation](installation.md) | Install, migrate, optional extensions and config. |
| [Scheduling](scheduling.md) | Frequency helpers and the cron scheduler. |
| [Queues & Backends](QUEUES.md) | The `QueueBackend` contract and the five backends. |
| [CLI Commands](COMMANDS.md) | `jobs:queue:work`, `jobs:queue:reap`, `jobs:cronjob:run`, `jobs:queue:purge`. |
| [Configuration](CONFIGURATION.md) | Every option in `Config\Jobs`. |
| [Retries](RETRIES.md) | Backoff strategies and retry semantics. |
| [Architecture](ARCHITECTURE.md) | Definition, envelope, runtime and worker pipeline. |
| [Dependencies](dependencies.md) | Job dependency ordering. |
| [Migration v1 → v3](MIGRATION-v1-to-v3.md) | Upgrading from the removed v1 API. |
| [Changelog](../CHANGELOG.md) | Release history. |

---

## Core Concepts

- **`JobBuilder` → `JobDefinition`**: the builder is a throwaway accumulator; the definition is an immutable value object.
- **Handlers** implement `JobHandlerInterface` and receive a read-only `JobContext` — they never see the builder. Built-in keys: `command`, `shell`, `closure`, `event`, `url`.
- **Backends** share one `QueueBackend` contract (`enqueue` / `fetch` / `ack` / `nack` / `abandon` / `reapExpired`) with at-least-once delivery.
- **The worker** runs a single attempt per fetch; retries are the backend's responsibility (requeue with backoff).

---

## Contributing

Contributions and feedback are welcome — open an issue or PR on [GitHub](https://github.com/daycry/jobs).

## License

MIT License. See the repository for the full text.
