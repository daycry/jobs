# Jobs: Defining Work

This page describes the v3 job **definition model** — the way you describe a unit of work before it
runs. In v3 a job is split into a small set of focused objects: you start from the
`Daycry\Jobs\Jobs` facade, accumulate configuration on a fluent `JobBuilder`, and materialise an
immutable `JobDefinition`. When the definition is dispatched it is serialised into a canonical
**wire envelope** that travels through the queue, independently of which backend you use.

Nothing on this page carries scheduling/queue state into your handlers — that is the
[handler contract](handlers.md)'s job. Here we focus only on *describing* and *dispatching* work.

- [The facade: `Jobs`](#the-facade-jobs)
- [`JobBuilder`: the fluent accumulator](#jobbuilder-the-fluent-accumulator)
- [`JobDefinition`: the immutable value object](#jobdefinition-the-immutable-value-object)
- [The wire envelope](#the-wire-envelope)
- [Field reference and behaviour](#field-reference-and-behaviour)

---

## The facade: `Jobs`

`Daycry\Jobs\Jobs` is the single static entry point. It has exactly two methods:

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| `define` | `Jobs::define(string $handler, mixed $payload = null)` | `JobBuilder` | Open a fluent builder for the given handler key and payload. |
| `backend` | `Jobs::backend(?string $name = null)` | `QueueBackend` | Resolve a configured backend directly (null = the default `$worker`). |

```php
use Daycry\Jobs\Jobs;

// Describe + enqueue a job in one fluent chain.
$id = Jobs::define('command', 'app:report')
    ->named('daily-report')
    ->queue('reports')
    ->maxRetries(3)
    ->dispatch();

// Resolve a backend directly (e.g. to enqueue a pre-built definition, or to fetch/ack manually).
$backend = Jobs::backend('redis');
```

> **Note:** The first argument to `define()` is a **handler key**, not a class name. Keys are mapped
> to handler classes in `Config\Jobs::$handlers`. The five built-in keys are `command`, `shell`,
> `closure`, `event` and `url`. See [Handlers](handlers.md) for each handler's payload shape, and
> [Configuration](CONFIGURATION.md) for registering your own keys.

---

## `JobBuilder`: the fluent accumulator

`Daycry\Jobs\Definition\JobBuilder` is a **mutable, throwaway accumulator**. Every setter returns
`$this`, so calls chain. When you are done you call a terminator: `toDefinition()` produces an
immutable `JobDefinition`, and `dispatch()` enqueues it. The builder never runs anything itself.

### Builder defaults

A fresh builder starts with these defaults (constructor `__construct(string $handler, mixed $payload = null)`):

| Property | Default |
|----------|---------|
| `priority` | `5` |
| `maxRetries` | `0` (no retries) |
| `timeout` | `null` (use config `defaultTimeout`) |
| `singleInstance` | `false` |
| `enabled` | `true` |
| `cron expression` | `* * * * *` (every minute) |
| `queue` / `name` / `scheduledAt` / `idempotencyKey` | `null` |
| `environments` / `dependsOn` | `[]` |

### Identity & routing methods

| Method | Description |
|--------|-------------|
| `named(string $name): self` | Set a friendly name used in logs, metrics, the single-instance lock key, and dependency ordering. Defaults to `handler:hash(payload)` when omitted. |
| `queue(?string $queue): self` | Target queue. `null` means "use the first configured queue" (`default` on the wire). |
| `priority(int $priority): self` | Higher = sooner. How strictly this is honoured is backend-dependent. Default `5`. |

```php
Jobs::define('command', 'app:cleanup')
    ->named('nightly-cleanup')
    ->queue('maintenance')
    ->priority(9)
    ->dispatch();
```

### Reliability methods

| Method | Description |
|--------|-------------|
| `maxRetries(?int $maxRetries): self` | Number of retries **after** the first attempt. Total runs = `maxRetries + 1`. `0` (default) = run once, no retry. |
| `timeout(?int $timeout): self` | Per-attempt timeout in seconds. `null` falls back to `Config\Jobs::$defaultTimeout`. |
| `singleInstance(bool $singleInstance = true): self` | Prevent overlapping runs of the same named job via a cache-backed ownership lock. |
| `idempotencyKey(?string $idempotencyKey): self` | Opt-in deduplication key. `EnvelopeFactory::toWire()` serialises it onto the wire (as a signed identity field), and the worker skips a message whose key was already processed within `idempotencyTtl` — acking it without running. See the [`idempotencyKey` and `singleInstance`](#idempotencykey-and-singleinstance) note below. |

```php
Jobs::define('command', 'app:reconcile')
    ->named('reconcile')
    ->maxRetries(5)        // up to 6 total runs
    ->timeout(120)         // interrupt after 120s per attempt
    ->singleInstance()     // never run two at once
    ->idempotencyKey('reconcile-2026-06-03')
    ->dispatch();
```

> **Note:** Retries are applied by the **worker**, not by the runtime. On a failed attempt with
> retries remaining, the worker `nack`s the lease with a backoff delay so the backend redelivers it.
> See [Retries](RETRIES.md) and [Advanced topics](advanced.md#idempotency-in-depth).

### Gating methods

| Method | Description |
|--------|-------------|
| `enabled(bool $enabled = true): self` | Mark the definition active/inactive. Disabled scheduled jobs are skipped by the cron runner. |
| `disable(): self` | Shorthand for `enabled(false)`. |
| `environments(array\|string ...$envs): self` | Restrict execution to the listed CodeIgniter environments. Accepts a variadic list or a single array. Empty = no restriction. |
| `dependsOn(array\|string ...$names): self` | Declare job **names** that must run first within the same scheduler run. Accepts a variadic list or a single array. |

```php
// Only run in production and staging; skip everywhere else.
Jobs::define('command', 'app:send-newsletter')
    ->named('newsletter')
    ->environments('production', 'staging')
    ->dailyAt('08:00')
    ->queue('mail');

// Dependency ordering (resolved by the scheduler's topological sort).
$scheduler->define('command', 'app:extract')->named('extract')->dailyAt('01:00');
$scheduler->define('command', 'app:transform')->named('transform')->dependsOn('extract');
$scheduler->define('command', 'app:load')->named('load')->dependsOn('transform');
```

> **Warning:** `dependsOn()` only orders jobs **within a single scheduler run**
> (`jobs:cronjob:run`). It does not create cross-process or cross-queue dependencies, and it does not
> wait for an enqueued job to finish on a worker. See [Dependencies](dependencies.md).

### Scheduling methods

`scheduledAt()` and the cron frequency helpers describe *when* a job should run. They are
complementary: `scheduledAt()` is a one-shot earliest-run time for queued work, while the cron
helpers describe a recurring schedule consumed by the cron runner.

| Method | Description |
|--------|-------------|
| `scheduledAt(?DateTimeImmutable $when): self` | Earliest run time (one-shot delay). Travels on the wire as the `schedule` field. |
| `cron(string $expression): self` | Set the schedule from a raw 5-field crontab string. Throws `RuntimeException` on an invalid expression. |

Frequency helpers (each recomposes the underlying 5-field cron expression):

| Method | Resulting cron | Meaning |
|--------|----------------|---------|
| `everyMinute(?int $minutes = null)` | `*` or `*/n * * * *` | Every minute, or every *n* minutes. |
| `everyXMinutes(int $minutes)` | `*/n * * * *` | Every *n* minutes (explicit interval). |
| `hourly()` | `0 * * * *` | Top of every hour. |
| `hourlyAt(int $minute)` | `m * * * *` | At minute *m* past every hour. |
| `daily()` | `0 0 * * *` | Every day at midnight. |
| `dailyAt(string $time)` | `M H * * *` | Every day at `HH:MM` (normalised without leading zeros). |
| `weekly()` | `0 0 * * 0` | Sundays at midnight. |
| `monthly()` | `0 0 1 * *` | First of the month at midnight. |
| `quarterly()` | `0 0 1 */3 *` | First day of each quarter at midnight. |
| `yearly()` | `0 0 1 1 *` | First of January at midnight. |

```php
// One-shot delay: do not run before this instant.
Jobs::define('command', 'app:remind')
    ->scheduledAt(new DateTimeImmutable('2026-06-10 09:00:00'))
    ->queue('reminders')
    ->dispatch();

// Recurring schedule (consumed by jobs:cronjob:run; see Scheduling).
$scheduler->define('command', 'app:report')->named('report')->dailyAt('02:30')->queue('reports');

// Raw cron expression.
$scheduler->define('command', 'app:every-15')->named('every-15')->cron('*/15 * * * *');
```

> **Note:** `dailyAt('02:30')` produces the cron fields `30 2 * * *` — hours and minutes are
> normalised without leading zeros, matching standard crontab output. See [Scheduling](scheduling.md)
> for how the cron runner evaluates these expressions.

### Terminators

| Method | Returns | Description |
|--------|---------|-------------|
| `toDefinition(): JobDefinition` | `JobDefinition` | Materialise the accumulated config into an immutable value object **without** enqueuing. |
| `dispatch(?string $backend = null): string` | `string` | Build the definition and enqueue it onto the named backend (or the configured default), returning the backend-assigned id. |

```php
// Build a definition without dispatching (useful for tests or manual enqueue).
$definition = Jobs::define('command', 'app:report')->maxRetries(2)->toDefinition();

// Dispatch to an explicit backend.
$id = Jobs::define('command', 'app:report')->queue('reports')->dispatch('database');
```

---

## `JobDefinition`: the immutable value object

`Daycry\Jobs\Definition\JobDefinition` is a `final readonly` value object: every `withXxx()` helper
returns a **new** instance, so a definition can be safely shared across enqueue sites without any
spooky action at a distance. The builder produces one via `toDefinition()`; you can also construct
one directly.

### Fields

| Field | Type | Default | Meaning |
|-------|------|---------|---------|
| `handler` | `string` | — | Handler key (must exist in `Config\Jobs::$handlers`). |
| `payload` | `mixed` | — | Arbitrary payload passed to the handler. |
| `name` | `?string` | `null` | Friendly name; defaults to `handler:hash(payload)`. |
| `queue` | `?string` | `null` | Explicit queue; `null` = "first configured queue". |
| `priority` | `int` | `5` | Higher = sooner (backend-dependent). |
| `maxRetries` | `?int` | `0` | Retries after the first attempt; total runs = `maxRetries + 1`. |
| `timeout` | `?int` | `null` | Per-attempt soft timeout (seconds); `null` = config `defaultTimeout`. |
| `scheduledAt` | `?DateTimeImmutable` | `null` | Earliest run time (UTC); `null` = as soon as possible. |
| `singleInstance` | `bool` | `false` | Lock to prevent concurrent runs of the same name. |
| `environments` | `list<string>` | `[]` | Restrict to these CI4 environments; empty = no restriction. |
| `dependsOn` | `list<string>` | `[]` | Job names that must succeed first within a scheduler run. |
| `cronExpression` | `string` | `* * * * *` | Cron schedule used by the scheduler. |
| `meta` | `array<string, mixed>` | `[]` | Free-form metadata propagated to the envelope. |
| `enabled` | `bool` | `true` | Whether the definition is active. |
| `idempotencyKey` | `?string` | `null` | Optional dedup key. |

### Immutable mutation (`withXxx`)

Each field has a matching `withXxx()` copy-on-write helper:
`withName`, `withQueue`, `withPriority`, `withMaxRetries`, `withTimeout`, `withScheduledAt`,
`withSingleInstance`, `withEnvironments`, `withDependsOn`, `withCronExpression`, `withMeta`,
`withEnabled`, `withIdempotencyKey`.

```php
use Daycry\Jobs\Definition\JobDefinition;

$base = Jobs::define('command', 'app:report')->named('report')->toDefinition();

// Derive variants without mutating $base.
$urgent  = $base->withPriority(9)->withQueue('high');
$delayed = $base->withScheduledAt(new DateTimeImmutable('+1 hour'));

// $base is unchanged: $base->priority === 5, $base->queue === null.
```

> **Note:** Because every `withXxx()` returns a new object, you can build a single "template"
> definition and fan it out into many enqueue sites — each variant is independent.

---

## The wire envelope

When a definition is dispatched, `Daycry\Jobs\Queues\EnvelopeFactory::toWire()` converts it into a
canonical JSON object that every backend stores identically. Centralising this guarantees that a
message enqueued through one backend has the exact same structure as one enqueued through another.

### Wire shape

```json
{
  "job":            "command",
  "payload":        "app:report",
  "queue":          "reports",
  "priority":       5,
  "maxRetries":     3,
  "attempts":       0,
  "name":           "daily-report",
  "identifier":     "1d4f...",
  "idempotencyKey": null,
  "schedule":       "2026-06-10 09:00:00",
  "_sig":           "9a3b...hmac-sha256-hex..."
}
```

| Wire field | Source | Notes |
|------------|--------|-------|
| `job` | `JobDefinition::$handler` | The handler key to resolve at consume time. |
| `payload` | `JobDefinition::$payload` | The handler's input. |
| `queue` | `JobDefinition::$queue ?? 'default'` | Target queue (defaults to `default`). |
| `priority` | `JobDefinition::$priority` | Normalised priority. |
| `maxRetries` | `JobDefinition::$maxRetries` | Drives the worker's retry decision. |
| `attempts` | (worker-managed) | Completed runs **before** the current one (0-based). Starts at `0`; the backend re-stamps it on requeue. |
| `name` | `JobDefinition::$name` | Logical name. |
| `identifier` | backend-assigned id | Unique id for traceability. |
| `idempotencyKey` | `JobDefinition::$idempotencyKey` | Opt-in dedup key (`null` if unset). Part of the signed identity. |
| `schedule` | `JobDefinition::$scheduledAt` | `Y-m-d H:i:s` or `null`. |
| `_sig` | `EnvelopeSigner` | HMAC-SHA256 over the **immutable identity fields** only. |

### Signature scope

The `_sig` HMAC is computed over a deterministic JSON of the **immutable** identity fields:
`job`, `payload`, `queue`, `priority`, `maxRetries`, `name`, `identifier`, `idempotencyKey`. The
mutable `attempts` and `schedule` fields and `_sig` itself are **excluded** so the signature survives a
requeue (when `attempts` is incremented). The worker re-verifies `_sig` after fetch and rejects any
message whose signature is missing or invalid.

> **Warning:** Anything that contributes to the signature is what the worker trusts. Because
> `attempts` is excluded, a backend can safely bump the retry counter on redelivery without
> invalidating the signature — but it also means `attempts` is not itself tamper-evident. Treat it as
> advisory and keep handlers idempotent. See [Advanced topics](advanced.md#envelope-signing) and
> [Configuration](CONFIGURATION.md) for the signing key.

The `JobEnvelope`/`JobLease` objects that wrap the wire payload on the consume side are documented in
[Queues & Backends](QUEUES.md); this page covers only what the producer puts onto the wire.

---

## Field reference and behaviour

### `priority`

Higher numbers run sooner. The default is `5`. Enforcement depends on the backend's native priority
support (e.g. Beanstalk has priorities; some backends approximate ordering). See
[Queues & Backends](QUEUES.md).

### `scheduledAt` vs `cron`

These answer different questions:

- **`scheduledAt(DateTimeImmutable)`** — a *one-shot* "do not run before this instant". It is serialised
  onto the wire as `schedule` and used by delay-capable backends to hold the message until due.
- **`cron(...)` / frequency helpers** — a *recurring* schedule stored as `cronExpression` on the
  definition. It is evaluated by the cron runner (`jobs:cronjob:run`) to decide when a scheduled job
  is due; it does not travel on the queue wire.

```php
// One-shot: enqueue now, run no earlier than the given time.
Jobs::define('command', 'app:remind')
    ->scheduledAt(new DateTimeImmutable('+30 minutes'))
    ->queue('reminders')
    ->dispatch();

// Recurring: register with the scheduler; jobs:cronjob:run enqueues/runs it when due.
$scheduler->define('command', 'app:report')->named('report')->everyXMinutes(15)->queue('reports');
```

### `dependsOn` and `environments`

`dependsOn` is used by the scheduler's topological sort: jobs are executed in dependency order, and a
circular or unknown dependency throws. `environments` is checked against the current CI4 environment;
jobs not listed for the active environment are skipped. Both are detailed in
[Dependencies](dependencies.md) and [Scheduling](scheduling.md).

### `enabled`

A disabled definition (`->disable()` or `->enabled(false)`) is skipped by the cron runner. This lets
you keep a scheduled job declared but inactive without deleting its registration.

### `idempotencyKey` and `singleInstance`

These guard two different problems:

- **`idempotencyKey`** prevents the *same logical message* from being processed twice (at-least-once
  delivery can redeliver). It is opt-in: `EnvelopeFactory::toWire()` writes `idempotencyKey` onto the
  wire and includes it in the signed identity fields, so tampering with the key breaks signature
  verification. When a wire message carries the key, the worker consults `IdempotencyGuard` and
  acks-without-running a repeat seen within `idempotencyTtl` (status `skipped-idempotent`). Because
  delivery is at-least-once and the dedupe is best-effort under crash/redelivery, still make your
  handlers idempotent.
- **`singleInstance`** prevents *two runs at the same time* of the same named job. `JobRuntime`
  acquires a cache-backed ownership lock; contention surfaces as a failed result (so the worker can
  requeue it to run once the holder finishes).

```php
Jobs::define('command', 'app:rebuild-index')
    ->named('rebuild-index')
    ->singleInstance()                 // never overlap
    ->idempotencyKey('rebuild-index')  // deduplicated on the worker within idempotencyTtl
    ->queue('search')
    ->dispatch();
```

Both mechanisms are explained in depth — including their best-effort atomicity caveats — in
[Advanced topics](advanced.md#idempotency-in-depth) and
[Advanced topics → Single instance](advanced.md#single-instance).

---

## See also

- [Handlers](handlers.md) — the handler contract, the five built-ins, and writing your own.
- [Queues & Backends](QUEUES.md) — the `QueueBackend` contract and the five backends.
- [Scheduling](scheduling.md) — the cron runner and frequency semantics.
- [Retries](RETRIES.md) — backoff strategies and retry semantics.
- [Configuration](CONFIGURATION.md) — every option in `Config\Jobs`.
- [Advanced topics](advanced.md) — idempotency, signing, single-instance and extensibility.
