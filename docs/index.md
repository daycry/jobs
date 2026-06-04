---
hide:
  - toc
---

<div class="hero" markdown>

# CodeIgniter Jobs

Job scheduling and queue processing for **CodeIgniter 4**. Describe work with a fluent, immutable
builder, dispatch it to one of five interchangeable queue backends, and process it with a resilient
worker — real timeouts, retries with backoff, HMAC-signed envelopes, idempotency and per-queue
handler allowlists.

[Get started](quickstart.md){ .md-button .md-button--primary }
[View on GitHub](https://github.com/daycry/jobs){ .md-button }

</div>

!!! note "v3.0 is a single, clean architecture"
    The legacy mutable `Job` builder, the V1 `Scheduler`, the performance loggers, the
    `NotificationService`/email integration, the `QueueManager` and the `JobsLogModel` were all
    removed. Upgrading from v1? See [Migration v1 → v3](MIGRATION-v1-to-v3.md).

## Features

<div class="grid cards" markdown>

-   :material-format-list-checks:{ .lg .middle } **Fluent, immutable definitions**

    ---

    `Jobs::define()` opens a throwaway `JobBuilder`; the result is a `readonly` `JobDefinition`
    value object. No mutable god-object.

    [:octicons-arrow-right-24: Jobs & Builder](jobs.md)

-   :material-swap-horizontal:{ .lg .middle } **Five backends, one contract**

    ---

    `sync`, `database`, `redis`, `beanstalk` and `serviceBus` all implement the same lease-based
    `QueueBackend` — at-least-once delivery on every persistent backend.

    [:octicons-arrow-right-24: Queues & Workers](QUEUES.md)

-   :material-shield-check:{ .lg .middle } **Secure by default**

    ---

    HMAC-signed envelopes, a per-queue handler allowlist, deny-by-default `shell`, an event
    allowlist and an anti-SSRF `url` handler.

    [:octicons-arrow-right-24: Security](security.md)

-   :material-timer-outline:{ .lg .middle } **Real timeouts & retries**

    ---

    `pcntl` interrupts even CPU-bound code at the deadline; retries with `none` / `fixed` /
    `exponential` backoff and a configurable budget.

    [:octicons-arrow-right-24: Retries](RETRIES.md)

-   :material-calendar-clock:{ .lg .middle } **Cron scheduling**

    ---

    Frequency helpers (`dailyAt`, `everyMinute`, `hourly`, …), topological `dependsOn()` ordering
    and environment gating.

    [:octicons-arrow-right-24: Scheduling](scheduling.md)

-   :material-cog-sync:{ .lg .middle } **Production-ready operations**

    ---

    Circuit breaker, per-queue rate limits, graceful shutdown, a lease reaper, metrics and an
    opt-in dead-letter helper.

    [:octicons-arrow-right-24: Operations](operations.md)

</div>

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

!!! note
    With the zero-config default backend (`sync`), `dispatch()` runs the job **inline** and returns
    a synthetic `sync-...` id. Switch `Config\Jobs::$worker` to `database`, `redis`, `beanstalk` or
    `serviceBus` to actually enqueue for a separate worker process.

### 2. Run a worker

```bash
php spark jobs:queue:work reports
```

The worker leases one ready message at a time, verifies its signature, runs a single attempt, then
`ack`s on success or `nack`s (with backoff) / `abandon`s on failure. Stop it gracefully with
++ctrl+c++ (SIGINT) or SIGTERM.

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

Due definitions with a `queue` are **enqueued**; the rest run **inline**. See
[Quick Start](quickstart.md) for the full end-to-end tutorial, including a custom handler and
envelope signing.

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

Contributions and feedback are welcome on [GitHub](https://github.com/daycry/jobs). Released under
the MIT License.
