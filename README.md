[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.com/donate?business=SYC5XDT23UZ5G&no_recurring=0&item_name=Thank+you%21&currency_code=EUR)

---

🚀 **[VIEW ONLINE DOCUMENTATION](https://codeigniter-4-jobs.readthedocs.io/)** 🚀

---

# Codeigniter Jobs

[![Documentation Status](https://readthedocs.org/projects/codeigniter-4-jobs/badge/?version=latest)](https://codeigniter-4-jobs.readthedocs.io/en/latest/)
[![Tests](https://github.com/daycry/jobs/actions/workflows/php.yml/badge.svg?branch=master)](https://github.com/daycry/jobs/actions/workflows/php.yml)
[![Static Analysis](https://github.com/daycry/jobs/actions/workflows/analyze.yml/badge.svg?branch=master)](https://github.com/daycry/jobs/actions/workflows/analyze.yml)
[![Code Style](https://github.com/daycry/jobs/actions/workflows/cs.yml/badge.svg?branch=master)](https://github.com/daycry/jobs/actions/workflows/cs.yml)
[![Composer](https://github.com/daycry/jobs/actions/workflows/composer.yml/badge.svg?branch=master)](https://github.com/daycry/jobs/actions/workflows/composer.yml)
[![Security](https://github.com/daycry/jobs/actions/workflows/security.yml/badge.svg?branch=master)](https://github.com/daycry/jobs/actions/workflows/security.yml)
[![Coverage status](https://coveralls.io/repos/github/daycry/jobs/badge.svg?branch=master)](https://coveralls.io/github/daycry/jobs?branch=master)
[![Downloads](https://img.shields.io/packagist/dt/daycry/jobs)](https://packagist.org/packages/daycry/jobs)
[![Monthly Downloads](https://img.shields.io/packagist/dm/daycry/jobs)](https://packagist.org/packages/daycry/jobs)
[![GitHub release (latest by date)](https://img.shields.io/github/v/release/daycry/jobs)](https://packagist.org/packages/daycry/jobs)
[![GitHub stars](https://img.shields.io/github/stars/daycry/jobs)](https://packagist.org/packages/daycry/jobs)
[![GitHub license](https://img.shields.io/github/license/daycry/jobs)](https://github.com/daycry/jobs/blob/master/LICENSE)

Job scheduling and queue processing for CodeIgniter 4. Define work with a fluent, immutable builder, dispatch it to one of several queue backends (Sync, Database, Redis, Beanstalk, Azure Service Bus), and process it with a resilient worker featuring real timeouts, retries with backoff, signed envelopes, idempotency and per-queue handler allowlists.

> **v3.0** is a complete, single-architecture rewrite. The legacy mutable `Job` builder, the V1 `Scheduler`, the performance loggers and the `QueueManager` have been removed. See the [Migration v1 → v3](docs/MIGRATION-v1-to-v3.md) guide if you are upgrading.

<p align="center">
<a href="#installation">Installation</a> ·
<a href="#quick-start">Quick Start</a> ·
<a href="#handlers">Handlers</a> ·
<a href="#queue-backends">Backends</a> ·
<a href="#cli-commands">Commands</a> ·
<a href="#scheduling-cron">Cron</a> ·
<a href="#security">Security</a>
</p>
<p align="center">
<a href="docs/CONFIGURATION.md">Configuration</a> ·
<a href="docs/QUEUES.md">Queues</a> ·
<a href="docs/COMMANDS.md">CLI Commands</a> ·
<a href="docs/RETRIES.md">Retries</a> ·
<a href="docs/ARCHITECTURE.md">Architecture</a>
</p>

---

## Features

- **Fluent definition API**: `Jobs::define()` returns a throwaway `JobBuilder` that accumulates configuration and materialises an immutable `JobDefinition` on `dispatch()` / `toDefinition()`.
- **Five built-in handlers**: `command`, `shell`, `closure`, `event`, `url` — each receiving an immutable `JobContext`, never the builder.
- **Five queue backends** behind one `QueueBackend` contract: `sync`, `database`, `redis`, `beanstalk`, `serviceBus`.
- **Cron scheduler**: register recurring jobs in `Config\Jobs::init()`; due jobs are run inline or enqueued, in topological dependency order.
- **Resilient worker**: a single attempt per fetch, retries via the backend with backoff, real (interrupting) timeouts, circuit breaker, per-queue rate limits and a dead-letter queue.
- **Secure by default**: HMAC-SHA256 envelope signing, per-queue handler allowlists, deny-by-default `ShellHandler`, event allowlist and SSRF-hardened `UrlHandler`.
- **At-least-once delivery** on every persistent backend, recovered by `reapExpired()`; opt-in idempotency keeps re-deliveries safe.

## Installation

```bash
composer require daycry/jobs
```

Run the package migrations (queue table, lease columns, history table):

```bash
php spark migrate -n "Daycry\Jobs"
```

Optionally publish the config so you can customise it under `app/Config/Jobs.php`:

```bash
php spark jobs:publish
```

Backend-specific extensions are optional and only needed for the backend you use:

- `ext-redis` for the `redis` backend.
- `pda/pheanstalk` for the `beanstalk` backend.
- `ext-pcntl` (Unix) for real, interrupting timeouts and graceful worker shutdown.

Requires PHP `^8.2` and CodeIgniter 4. See [Installation](docs/installation.md) for details.

## Quick Start

Define and dispatch a job with the fluent facade:

```php
use Daycry\Jobs\Jobs;

// Enqueue a spark command to run daily at 02:00 on the "reports" queue, with 3 retries.
$id = Jobs::define('command', 'app:report')
    ->named('daily-report')
    ->dailyAt('02:00')
    ->queue('reports')
    ->maxRetries(3)
    ->timeout(120)
    ->dispatch();
```

`dispatch()` enqueues the definition onto the configured default backend (`Config\Jobs::$worker`) and returns the backend-assigned id. Pass a name to target a specific backend, e.g. `->dispatch('redis')`.

To build a definition without enqueuing it (e.g. for tests), use `toDefinition()`:

```php
$definition = Jobs::define('closure', static fn (): string => 'done')->toDefinition();
```

Resolve a backend directly when you need lower-level access:

```php
$backend = Jobs::backend('database'); // or Jobs::backend() for the default worker
```

### Builder API

`Jobs::define(string $handler, mixed $payload = null): JobBuilder` opens the builder. Chain any of:

| Method | Purpose |
|--------|---------|
| `named(string)` | Friendly name for logs/metrics. |
| `queue(?string)` | Target queue; `null` lets the runner pick the first configured queue. |
| `priority(int)` | Higher = sooner (backend-dependent). Default `5`. |
| `maxRetries(?int)` | Retries after the first attempt. Total runs = `maxRetries + 1`. |
| `timeout(?int)` | Per-attempt timeout in seconds (falls back to `defaultTimeout`). |
| `scheduledAt(DateTimeImmutable)` | Earliest run time. |
| `singleInstance(bool)` | Prevent concurrent runs of the same job. |
| `environments(...)` | Restrict to specific CI4 environments. |
| `dependsOn(...)` | Names that must succeed first (scheduler only). |
| `idempotencyKey(?string)` | Opt-in deduplication key. |
| `enabled(bool)` / `disable()` | Toggle a definition on/off. |

Scheduling helpers: `cron(string)`, `everyMinute(?int)`, `everyXMinutes(int)`, `hourly()`, `hourlyAt(int)`, `daily()`, `dailyAt('HH:MM')`, `weekly()`, `monthly()`, `quarterly()`, `yearly()`.

Terminators: `toDefinition(): JobDefinition` and `dispatch(?string $backend = null): string`.

## Handlers

A handler contains business logic only. It implements `JobHandlerInterface` and receives an immutable `JobContext` ( `payload`, `name`, `queue`, `attempt`, `meta` ):

```php
use Daycry\Jobs\Handlers\JobHandlerInterface;
use Daycry\Jobs\Execution\JobContext;

interface JobHandlerInterface
{
    public function handle(JobContext $ctx): mixed;
    public function beforeRun(JobContext $ctx): void;          // optional lifecycle hook
    public function afterRun(JobContext $ctx, $result): void;  // optional lifecycle hook
}
```

Extend `AbstractJobHandler` to get no-op `beforeRun()` / `afterRun()` and implement only `handle()`. For typed payloads, extend `TypedJobHandler`: declare `payloadType(): string` and implement `run(object $dto)`; the base class rehydrates the DTO from the `JobContext` payload (array, object or JSON string).

```php
use Daycry\Jobs\Handlers\TypedJobHandler;

final class ProcessImport extends TypedJobHandler
{
    public function payloadType(): string { return ImportRequest::class; }

    protected function run(object $payload): mixed
    {
        // $payload is an ImportRequest instance hydrated from the queue message.
        return 'imported';
    }
}
```

### Built-in handler keys

Configured in `Config\Jobs::$handlers`:

| Key | Class | Payload |
|-----|-------|---------|
| `command` | `CommandHandler` | Non-empty spark command string, e.g. `'app:report'`. |
| `shell` | `ShellHandler` | A string or `argv` array; executed via `proc_open` (no shell). |
| `closure` | `ClosureHandler` | A callable; **sync backend only** (closures do not survive serialization). |
| `event` | `EventHandler` | `['name' => string, 'data' => array]`. |
| `url` | `UrlHandler` | `['method' => string, 'url' => string, 'options' => array]`. |

```php
Jobs::define('url', ['method' => 'GET', 'url' => 'https://example.com'])->queue('web')->dispatch();
Jobs::define('event', ['name' => 'user.registered', 'data' => ['user_id' => 123]])->dispatch();
```

## Queue Backends

All backends implement a single `QueueBackend` contract with lease semantics: `enqueue`, `fetch` (returns a lease), `ack`, `nack(?delay)`, `abandon`, `reapExpired`. Delivery is **at-least-once** on every persistent backend, so handlers should be idempotent.

| Name | Class | Notes |
|------|-------|-------|
| `sync` | `SyncBackend` | Runs inline at `enqueue()` time. Default. No worker needed. |
| `database` | `DatabaseBackend` | Persistent; `FOR UPDATE SKIP LOCKED`; reaped by `jobs:queue:reap`. |
| `redis` | `RedisBackend` | Reliable queue (RPOPLPUSH); reaped by `jobs:queue:reap`. |
| `beanstalk` | `BeanstalkBackend` | Native delayed retry; recovers leases natively. |
| `serviceBus` | `ServiceBusBackend` | Azure Service Bus peek-lock; recovers leases natively. |

Backends are configured in `Config\Jobs::$backends`; the active default is `Config\Jobs::$worker`. See [Queues](docs/QUEUES.md).

## CLI Commands

```bash
# Start a worker for a queue (graceful SIGTERM/SIGINT, circuit breaker, rate limits)
php spark jobs:queue:work reports
php spark jobs:queue:work reports --once            # process a single cycle and exit
php spark jobs:queue:work reports --max 100         # process at most N cycles
php spark jobs:queue:work reports --backend redis   # override the configured backend

# Reclaim leases from crashed/stalled workers (Database / Redis).
# Beanstalk and Service Bus recover leases natively.
php spark jobs:queue:reap reports
php spark jobs:queue:reap reports --backend redis

# Run due scheduled jobs — wire this to your OS cron, every minute.
php spark jobs:cronjob:run

# Maintenance: purge completed/failed rows from the database backend.
php spark jobs:queue:purge --status completed
```

See [CLI Commands](docs/COMMANDS.md) for every option.

## Scheduling (Cron)

Register recurring jobs in `Config\Jobs::init()`. Each `define()` returns the same fluent builder. Jobs that declare a `queue()` are **enqueued**; the rest run **inline**. `enabled()` / `environments()` are honoured, and `dependsOn()` produces a topological execution order.

```php
// app/Config/Jobs.php
use Daycry\Jobs\Cron\Scheduler;

public function init(Scheduler $scheduler): void
{
    $scheduler->define('command', 'app:report')
        ->named('reports')
        ->dailyAt('03:00')
        ->queue('reports');                 // enqueued to the "reports" queue

    $scheduler->define('shell', 'ls')
        ->named('shell_test')
        ->everyMinute()
        ->singleInstance();                 // runs inline (no queue)

    $scheduler->define('command', 'app:send-report')
        ->named('send-reports')
        ->dailyAt('03:30')
        ->dependsOn('reports');             // runs after "reports"
}
```

Run the scheduler from your system cron every minute:

```cron
* * * * * cd /path/to/app && php spark jobs:cronjob:run >> /dev/null 2>&1
```

## Security

- **Envelope signing**: queue envelopes are signed with HMAC-SHA256 over immutable identity fields at enqueue time. The key resolves from `Config\Jobs::$signingKey` → `env('JOBS_SIGNING_KEY')` → the CI4 Encryption key. When `$verifyEnvelopeSignature` is `true`, the worker **rejects** tampered or forged messages.
- **Per-queue handler allowlist**: `Config\Jobs::$queueHandlers` restricts which handler keys a queue may run, e.g. `['reports' => ['command'], 'web' => ['url', 'event']]`. Set this in production so remote queues cannot reach `shell` / `command`.
- **ShellHandler is deny-by-default**: an empty `$allowedShellCommands` rejects execution. Populate it with allowed binaries (matched by `realpath`), or opt out explicitly with `$allowAllShellCommands = true`. Commands run via `proc_open` with an `argv` array — never `/bin/sh -c`.
- **EventHandler allowlist**: only events listed in `$allowedEvents` may fire (empty = deny all).
- **UrlHandler anti-SSRF**: http/https only, private/reserved IPv4 + IPv6 targets rejected, SSL verification forced on, redirects disabled.

## Resilience

- **Retries**: `maxRetries` runs the job at most `maxRetries + 1` times. On failure with retries left the worker `nack`s with a backoff delay and the backend requeues; there is no blocking `sleep()` in the worker and no double-retry. Configure backoff with `retryBackoffStrategy` (`none` / `fixed` / `exponential`), `retryBackoffBase`, `retryBackoffMultiplier`, `retryBackoffMax`, `retryBackoffJitter`.
- **Real timeouts**: with `ext-pcntl`, a per-attempt timeout interrupts even tight loops; without it, a documented soft fallback applies.
- **Idempotency** (opt-in): set `idempotencyKey()`; the `IdempotencyGuard` deduplicates via cache for `$idempotencyTtl` seconds.
- **Single-instance lock** with an ownership token, **circuit breaker**, per-queue **rate limits** (`$queueRateLimits`) and an optional **dead-letter queue** (`$deadLetterQueue`).

See [Retries](docs/RETRIES.md) and [Architecture](docs/ARCHITECTURE.md).

## Configuration

All options live in `Config\Jobs` (`handlers`, `queueHandlers`, `backends`, `worker`, `queues`, retry/backoff, timeouts, signing, rate limits, DLQ, backend-specific settings). See [Configuration](docs/CONFIGURATION.md).

## Testing

```bash
composer test
```

## License

MIT © Daycry
