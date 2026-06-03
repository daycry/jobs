# Codeigniter Jobs

Advanced job scheduling and queue processing for CodeIgniter 4. A fluent, immutable definition API; five interchangeable queue backends; and a resilient worker with real timeouts, backoff retries, signed envelopes and security-by-default handlers.

> **v3.0** is a complete, single-architecture release. The legacy mutable `Job` builder, the V1 `Scheduler`, the performance loggers and the `QueueManager` have been removed. See [Migration v1 → v3](MIGRATION-v1-to-v3.md).

## Quick Overview

- **Fluent definition API**: `Jobs::define($handler, $payload)` returns a `JobBuilder`; `dispatch()` enqueues an immutable `JobDefinition` and returns its id.
- **Built-in handlers**: `command`, `shell`, `closure`, `event`, `url` — each receives an immutable `JobContext`.
- **Queue backends**: `sync`, `database`, `redis`, `beanstalk`, `serviceBus` behind one `QueueBackend` contract with at-least-once delivery and lease-based recovery.
- **Cron scheduler**: register recurring jobs in `Config\Jobs::init()`; due jobs run inline or are enqueued, in topological dependency order.
- **Resilient worker**: one attempt per fetch, retries via the backend with backoff, real (interrupting) timeouts, circuit breaker, per-queue rate limits and a dead-letter queue.
- **Secure by default**: HMAC-SHA256 envelope signing, per-queue handler allowlists, deny-by-default `ShellHandler`, event allowlist, SSRF-hardened `UrlHandler`.

## Getting Started

1. Install the package:
   ```bash
   composer require daycry/jobs
   ```
2. Run the migrations:
   ```bash
   php spark migrate -n "Daycry\Jobs"
   ```
3. (Optional) Publish the config and customise `app/Config/Jobs.php`:
   ```bash
   php spark jobs:publish
   ```
4. Define and dispatch a job:
   ```php
   use Daycry\Jobs\Jobs;

   Jobs::define('command', 'app:report')->queue('reports')->dispatch();
   ```
5. Process a queue with a worker:
   ```bash
   php spark jobs:queue:work reports
   ```
6. Run the scheduler from your OS cron, every minute:
   ```bash
   php spark jobs:cronjob:run
   ```

## Key Sections

| Topic | Description |
|-------|-------------|
| [Installation](installation.md) | Install, migrate, optional extensions. |
| [Scheduling](scheduling.md) | Frequency helpers and the cron scheduler. |
| [Queues & Backends](QUEUES.md) | The `QueueBackend` contract and backend notes. |
| [CLI Commands](COMMANDS.md) | Worker, reaper, cron runner and purge. |
| [Configuration](CONFIGURATION.md) | All tunables in `Config\Jobs`. |
| [Retries](RETRIES.md) | Backoff strategies and retry semantics. |
| [Architecture](ARCHITECTURE.md) | Internal components and the execution pipeline. |
| [Migration v1 → v3](MIGRATION-v1-to-v3.md) | Upgrading from the removed v1 API. |

## Contributing

Contributions and feedback are welcome. Please open issues or PRs at the GitHub repository.

## License

MIT License. See the full text in the repository.
