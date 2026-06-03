# Codeigniter Jobs

**daycry/jobs** is a job scheduling and queue processing library for CodeIgniter 4. You describe work with a fluent, immutable builder, dispatch it to one of five interchangeable queue backends, and process it with a resilient worker that provides real (interrupting) timeouts, retries with backoff, HMAC-signed envelopes, opt-in idempotency, single-instance locking and per-queue handler allowlists.

This page is the entry point: it explains what the package is, lists the key features, walks through a minimal quick start (define and dispatch a job, run a worker, schedule a cron job), and links to every documentation section.

> **Note:** v3.0 is a single, clean architecture. The legacy mutable `Job` builder, the V1 `Scheduler`, the performance loggers, the `NotificationService`/email integration, the `QueueManager` and the `JobsLogModel` were all removed. Upgrading from v1? See [Migration v1 → v3](MIGRATION-v1-to-v3.md).

---

## What it does

- **Define** a job once with a fluent builder (`Jobs::define()`), producing an immutable value object (`JobDefinition`).
- **Dispatch** it to a backend (`sync`, `database`, `redis`, `beanstalk` or `serviceBus`) that all share a single `QueueBackend` contract.
- **Process** it with `jobs:queue:work`, which runs exactly one attempt per delivery and lets the backend handle redelivery/backoff.
- **Schedule** recurring work with cron frequency helpers and the v3 `Scheduler`, driven by `jobs:cronjob:run` from your system cron.

---

## Key features

- **Fluent, immutable definitions** — `JobBuilder` is a throwaway accumulator; `JobDefinition` is a `readonly` value object. No mutable god-object.
- **Five queue backends, one contract** — `sync`, `database`, `redis`, `beanstalk`, `serviceBus`, all implementing `enqueue` / `fetch` / `ack` / `nack` / `abandon` / `reapExpired`.
- **At-least-once delivery** — persistent backends use lease semantics with owner tokens; a reaper reclaims leases abandoned by crashed workers.
- **Real timeouts** — `pcntl` SIGALRM interrupts even CPU-bound code at the deadline, with a documented soft fallback when `pcntl` is unavailable.
- **Retries with backoff** — `none` / `fixed` / `exponential` strategies with optional jitter; total runs = `maxRetries + 1`.
- **Signed envelopes** — HMAC-SHA256 over the immutable identity fields; the worker rejects tampered or forged messages.
- **Idempotency** — opt-in deduplication by key with a configurable TTL.
- **Single-instance locking** — token-owned locks prevent concurrent runs of the same named job.
- **Defense in depth** — per-queue handler allowlist, deny-by-default `shell`, event allowlist, anti-SSRF `url` handler.
- **Operational guards** — circuit breaker, per-queue rate limits, graceful shutdown, and an **opt-in** dead-letter helper (`DeadLetterQueue::store()`, gated by `Config\Jobs::$deadLetterQueue`). The worker itself calls `abandon()` when retries are exhausted; it does **not** auto-route to the DLQ.
- **Cron scheduling** — frequency helpers (`dailyAt`, `everyMinute`, `hourly`, …), topological ordering by `dependsOn()`, environment gating.

---

## Quick start

### 1. Define and dispatch a job

```php
use Daycry\Jobs\Jobs;

$id = Jobs::define('command', 'app:report')
    ->named('daily-report')
    ->queue('reports')
    ->maxRetries(3)
    ->dispatch();
```

`Jobs::define()` opens a fluent `JobBuilder`; `dispatch()` enqueues the resulting immutable
`JobDefinition` onto the configured backend and returns its backend-assigned id. Use
`toDefinition()` to build a definition without enqueuing, and `Jobs::backend(?string $name)` to
resolve a backend directly.

> **Note:** With the zero-config default backend (`sync`), `dispatch()` runs the job **inline** and
> returns a synthetic `sync-...` id. Switch `Config\Jobs::$worker` to `database`, `redis`,
> `beanstalk` or `serviceBus` to actually enqueue for a separate worker process.

### 2. Run a worker

```bash
php spark jobs:queue:work reports
```

The worker leases one ready message at a time, verifies its signature, runs a single attempt, then
`ack`s on success or `nack`s (with backoff) / `abandon`s on failure. Stop it gracefully with
`Ctrl+C` (SIGINT) or SIGTERM.

### 3. Schedule a recurring job

Register schedules in `Config\Jobs::init()`:

```php
public function init(\Daycry\Jobs\Cron\Scheduler $scheduler): void
{
    $scheduler->define('command', 'app:report')
        ->named('daily-report')
        ->dailyAt('02:00')
        ->queue('reports');
}
```

Then run the cron runner once per minute from your operating system's crontab:

```cron
* * * * * cd /var/www/app && php spark jobs:cronjob:run >> /dev/null 2>&1
```

Due definitions with a `queue` are **enqueued**; the rest run **inline**.

See [Quick Start](quickstart.md) for the full end-to-end tutorial, including a custom handler and
envelope signing.

---

## Documentation map

| Page | Description |
|------|-------------|
| [Installation](installation.md) | Requirements, `composer require`, migrations, optional extensions, signing key. |
| [Quick Start](quickstart.md) | End-to-end tutorial: handler, dispatch, worker, cron, signing. |
| [Architecture](ARCHITECTURE.md) | Layers, the pipeline, the `QueueBackend` contract, at-least-once semantics. |
| [Jobs & Builder](jobs.md) | `Jobs` facade, `JobBuilder`, `JobDefinition`, frequency helpers. |
| [Handlers](handlers.md) | `JobHandlerInterface`, `AbstractJobHandler`, `TypedJobHandler`, built-in handlers. |
| [Queues & Workers](QUEUES.md) | The five backends, `JobLease`, the worker loop. |
| [Scheduling](scheduling.md) | The `Scheduler`, cron expressions, dependency ordering. |
| [Retries](RETRIES.md) | Backoff strategies, `maxRetries`, DLQ. |
| [Security](security.md) | Signing, handler allowlists, shell/event/URL hardening. |
| [Concurrency](concurrency.md) | Single-instance locks, circuit breaker, rate limits. |
| [Advanced](advanced.md) | Callbacks/chaining model, idempotency in depth, custom backends. |
| [Configuration](CONFIGURATION.md) | Every option in `Config\Jobs`. |
| [CLI Commands](COMMANDS.md) | `jobs:queue:work`, `jobs:queue:reap`, `jobs:cronjob:run`, `jobs:queue:purge`, `jobs:publish`. |
| [Operations](operations.md) | Running workers in production, reaping, monitoring, purging. |
| [Attempts](ATTEMPTS.md) | The attempts counter, its lifecycle, and its relation to backoff. |
| [Dependencies](dependencies.md) | Ordering scheduled jobs with `dependsOn()` (topological sort). |
| [Exceptions](EXCEPTIONS.md) | `JobException` and `QueueException`. |
| [Migration v1 → v3](MIGRATION-v1-to-v3.md) | Upgrading from the removed v1 API. |

---

## Core concepts

- **`JobBuilder` → `JobDefinition`** — the builder is a throwaway accumulator; the definition is an
  immutable value object that travels through the queue as a `JobEnvelope`.
- **Handlers** implement `JobHandlerInterface` and receive a read-only `JobContext` — they never see
  the builder. Built-in keys: `command`, `shell`, `closure`, `event`, `url`.
- **Backends** share one `QueueBackend` contract with at-least-once delivery, so the same definition
  works on any backend.
- **The worker** runs a single attempt per fetch; retries are the backend's responsibility (requeue
  with backoff).

---

## Contributing

Contributions and feedback are welcome — open an issue or PR on
[GitHub](https://github.com/daycry/jobs).

## License

MIT License. See the repository for the full text.
