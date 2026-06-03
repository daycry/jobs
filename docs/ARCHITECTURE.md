# Architecture

v3 is a single, layered architecture. Each layer has one responsibility and communicates with the
next through small, immutable value objects. There is no mutable god-object: **definitions** describe
work, **handlers** contain only business logic, **backends** move messages, and the **worker**
orchestrates a single attempt per delivery. This page explains the layers, the value objects that
flow between them, the worker and cron pipelines, the `QueueBackend` contract, and the at-least-once
delivery guarantee.

If you are new to the package, read [Quick Start](quickstart.md) first; this page is the conceptual
map behind it.

---

## Layers

| Layer | Namespace | Responsibility |
|-------|-----------|----------------|
| Definition | `Daycry\Jobs\Definition` | Describe a job. `JobBuilder` (fluent, mutable accumulator) produces an immutable `JobDefinition` value object. |
| Handlers | `Daycry\Jobs\Handlers` | Business logic only. `JobHandlerInterface::handle(JobContext)`; `HandlerRegistry` resolves keys and enforces the per-queue allowlist. |
| Queues | `Daycry\Jobs\Queues` | Transport. One `QueueBackend` contract with lease semantics; `JobEnvelope` is the wire message, `JobLease` is an in-flight reservation. `EnvelopeFactory` builds and signs the wire payload; `BackendFactory` resolves backends from config. |
| Backends | `Daycry\Jobs\Queues\Backends` | Concrete transports: `SyncBackend`, `DatabaseBackend`, `RedisBackend`, `BeanstalkBackend`, `ServiceBusBackend`. |
| Signing | `Daycry\Jobs\Queues\Signing` | `EnvelopeSigner` — HMAC-SHA256 sign/verify with constant-time comparison. |
| Execution | `Daycry\Jobs\Execution` | Run a single attempt. `JobRuntime`, `JobContext`, `ExecutionResult`, `Timeout`, `RetryPolicy`/`RetryPolicyFixed`, `IdempotencyGuard`, `SingleInstanceLock`. |
| Worker | `Daycry\Jobs\Worker` | `QueueWorker` integrates the pipeline: fetch → verify → idempotency → run one attempt → ack/nack/abandon. `WorkerResult` reports the outcome. |
| Cron | `Daycry\Jobs\Cron` | `Scheduler` (fluent registry of `JobBuilder`s) and `CronRunner` (evaluate due definitions; enqueue or run inline). |
| Config | `Daycry\Jobs\Config` | `Config\Jobs` — handler/backend maps, security allowlists, retry/timeout settings, and `init()` to register scheduled jobs. |
| Support | `Daycry\Jobs\Libraries`, `Daycry\Jobs\Metrics` | Cross-cutting helpers: `CircuitBreaker`, `RateLimiter`, `DeadLetterQueue`, serializers, and the `MetricsCollectorInterface` collectors. |

---

## Core value objects

The layers talk to each other only through these small, mostly immutable objects:

- **`JobDefinition`** (`readonly`): *what the job is* — handler key, payload, scheduling, retry
  policy, identity. Every `withXxx()` helper returns a **new** instance, so a definition can be
  shared across enqueue sites without spooky action at a distance.
- **`JobEnvelope`** / wire payload: *how the definition travels* through a queue. The canonical wire
  object is:

  ```json
  { "job", "payload", "queue", "priority", "maxRetries", "attempts", "name", "identifier", "idempotencyKey", "schedule", "_sig" }
  ```

  Built by `EnvelopeFactory::toWire()`, identical across every backend so a message enqueued via one
  path can be consumed via another.
- **`JobLease`** (`readonly`): a reservation on an in-flight message —
  - `token`: opaque backend handle needed to `ack`/`nack` later (DB primary key, Redis processing
    entry, Service Bus `LockToken`, Beanstalk job id);
  - `ownerToken`: a random token minted by the worker at `fetch()` so a *reaped-then-reassigned*
    message cannot be `ack`ed by the previous owner;
  - `expiresAt`: the visibility deadline. After it passes the backend may redeliver; a long-running
    worker can `renew()` it.
- **`JobContext`** (`readonly`): *what a handler sees* — `payload`, `name`, `queue`, `attempt`,
  `meta`. Handlers never receive scheduling or queue state.
- **`ExecutionResult`**: the outcome of one handler run — `success`, `output`, `error`, `startedAt`,
  `endedAt` (and `durationSeconds()`), and the resolved `handlerClass`.

---

## Queue pipeline (worker)

`QueueWorker::processOnce($queue)` drives **one** message end to end:

1. **fetch** — `backend->fetch($queue)` leases one ready message, or returns `null` (status
   `empty`).
2. **verify** — when `verifyEnvelopeSignature` is on and a key is configured, the HMAC `_sig` is
   verified over the canonical identity JSON with `hash_equals()`. A tampered/forged message is
   `abandon()`ed (status `rejected`). An envelope whose payload is not a valid object is also
   `rejected`.
3. **idempotency** — if the envelope carries an `idempotencyKey`, `IdempotencyGuard::firstRun()`
   short-circuits a duplicate: `ack()` without running (status `skipped-idempotent`).
4. **run one attempt** — `JobRuntime::run(definition, context)` resolves the handler (enforcing the
   per-queue allowlist), acquires the single-instance lock if requested, applies the timeout,
   captures output, and returns an `ExecutionResult`. It runs **once** and never sleeps.
5. **decide** (the retry decision lives here, not in the runtime):
   - success → `ack()` (status `acked`),
   - failure with retries left (`attemptIndex < maxRetries`) → `nack($lease, $delay)` where `$delay`
     comes from the `RetryPolicy` (status `requeued`); the backend requeues,
   - retries exhausted → `abandon()` (status `dead-lettered`). `abandon()` routes to the backend's
     **native** dead-letter where one exists (Beanstalk `bury` / Service Bus after
     `MaxDeliveryCount`) and otherwise just drops the message (Database/Redis). The app-level
     `DeadLetterQueue` helper is opt-in and is **not** invoked by the worker.

Total runs for a job are therefore `maxRetries + 1`. Backoff is realised by the backend honouring
`nack($lease, $delaySeconds)`. See [Retries](RETRIES.md).

```text
                    ┌───────────────────────── QueueWorker::processOnce($queue) ─────────────────────────┐
                    │                                                                                     │
 fetch(lease) ──►  verify signature  ──►  idempotency guard  ──►  run ONE attempt (JobRuntime)           │
     │                   │                       │                        │                              │
   null              tampered                 duplicate                   │                              │
     │                   │                       │            ┌───────────┼───────────────┐              │
     ▼                   ▼                       ▼            ▼           ▼                 ▼              │
  "empty"           abandon()                 ack()        success   failure (retries)  retries exhausted │
                  "rejected"          "skipped-idempotent"   │            │                 │             │
                                                            ack()     nack(delay)        abandon()        │
                                                          "acked"   "requeued"        "dead-lettered"     │
                                                                  (backend requeues)  native DLQ or drop  │
                                                                                      (app DLQ is opt-in) │
                    └─────────────────────────────────────────────────────────────────────────────────┘
```

The CLI command `jobs:queue:work` wraps this loop with a circuit breaker, per-queue rate limits and
graceful shutdown. See [CLI Commands](COMMANDS.md) and [Concurrency](concurrency.md).

---

## Cron pipeline

1. `jobs:cronjob:run` builds a `Scheduler` and calls `Config\Jobs::init($scheduler)`.
2. `CronRunner::run()` walks the definitions in **topological order** of `dependsOn()`
   (`Scheduler::getExecutionOrder()` performs the sort and throws on circular/unknown dependencies).
3. For each definition it skips disabled jobs (`enabled()`) and jobs outside the current
   `environments()`, then evaluates the cron expression against "now" (or a frozen `-testTime`).
4. A due definition with a `queue` is **enqueued** via `BackendFactory::make(...)->enqueue()`;
   otherwise it runs **inline** through `JobRuntime` (one attempt). The runner never sleeps between
   jobs.

See [Scheduling](scheduling.md).

---

## The `QueueBackend` contract

A single contract underpins all five backends:

```php
interface QueueBackend
{
    /** Persist the definition into the queue. Returns the backend-assigned id. */
    public function enqueue(JobDefinition $definition): string;

    /** Lease one ready message, or null when the queue is empty (after any blocking timeout). */
    public function fetch(string $queue): ?JobLease;

    /** Mark the leased message processed; the backend removes it permanently. */
    public function ack(JobLease $lease): bool;

    /** Redeliver the message, optionally after $delaySeconds (retry backoff). */
    public function nack(JobLease $lease, ?int $delaySeconds = null): bool;

    /** Stop holding the lease without retrying (unprocessable / DLQ). */
    public function abandon(JobLease $lease): bool;

    /** Reclaim messages whose lease expired (crashed/stalled worker). Returns the count recovered. */
    public function reapExpired(string $queue, int $visibilityTimeout): int;
}
```

The contract is **stateless** with respect to the in-flight message: the worker holds the `JobLease`
and passes it back to `ack`/`nack`/`abandon`, so the backend never has to remember which message a
given worker is processing. This replaces the legacy split between a queue interface and a worker
interface that coupled the two sides through backend instance state.

| Backend | Notes |
|---------|-------|
| `SyncBackend` | `enqueue()` runs the job inline via `JobRuntime` and returns a synthetic `sync-...` id; `fetch()` is always `null`, the lease verbs are no-ops returning `true`. Ideal for tests/CLI. |
| `DatabaseBackend` | Atomic reservation; `reapExpired()` returns rows past the visibility timeout to a runnable state; requeue is an in-place `UPDATE` (no orphan rows). |
| `RedisBackend` | Reliable-queue pattern (waiting/processing lists) with owner tokens; `nack(delay)` uses a delayed ZSET; `reapExpired()` recovers stalled leases. |
| `BeanstalkBackend` | `nack()` re-serialises the payload with attempts+1 via delete + re-put (the native `release` verb cannot mutate the job body); the requested delay is applied on the re-put. `abandon()` buries the job (native DLQ). Per-job TTR makes the server recover stalled jobs natively (`reapExpired()` is a no-op). |
| `ServiceBusBackend` | Peek-lock with `serviceBusLockTimeout`; the broker redelivers on lock expiry. |

`reapExpired()` is exercised by `jobs:queue:reap` for the Database and Redis backends; Beanstalk and
Service Bus recover natively. See [Queues & Workers](QUEUES.md).

---

## At-least-once delivery

Every persistent backend provides **at-least-once** delivery, not exactly-once. A message can be
delivered (and a handler can therefore run) more than once. This happens by design when:

- a worker crashes after running a job but **before** `ack()` — the lease expires and the reaper (or
  the broker) makes the message eligible again;
- a `nack()` requeues a failed attempt for retry;
- a visibility timeout elapses while a slow job is still running (mitigated by setting the timeout
  above the maximum expected runtime, or by `JobLease::renew()`).

**Implications for your handlers:**

1. **Make handlers idempotent.** Running twice should not corrupt state (use upserts, conditional
   writes, or dedupe on a natural key).
2. **Use `idempotencyKey()` for hard dedupe.** When a definition carries an `idempotencyKey`,
   `EnvelopeFactory::toWire()` serialises it onto the envelope (as a signed identity field) and the
   worker, via `IdempotencyGuard`, skips a message whose key was already processed within
   `idempotencyTtl` — it `ack`s without running (status `skipped-idempotent`). This is opt-in. Because
   delivery is at-least-once and the dedupe is best-effort under crash/redelivery, still keep handlers
   idempotent at the application level. See [Idempotency in depth](advanced.md#idempotency-in-depth).
3. **Size visibility timeouts correctly.** `databaseVisibilityTimeout` / `redisProcessingVisibilityTimeout`
   / `serviceBusLockTimeout` must exceed the longest job, or a live job may be reclaimed and run
   concurrently. See [Configuration](CONFIGURATION.md).

> **Warning:** Owner tokens guard `ack`/`nack` so a previous owner cannot ack a reaped-and-reassigned
> message, but they do **not** prevent two runs of the same payload — that is the nature of
> at-least-once. Idempotency is the application-level guarantee.

---

## Execution & resilience

- **One attempt per fetch** — `JobRuntime` runs the handler exactly once; the worker owns the retry
  decision. This eliminates the legacy double-retry (a coordinator loop *and* a requeue helper both
  consuming `maxRetries`).
- **Timeout that interrupts** — `Timeout` installs a SIGALRM handler that *throws* (with
  `pcntl_async_signals(true)`), so even CPU-bound code is interrupted at the deadline. Without
  `pcntl` it degrades to a documented soft check.
- **Idempotency** — opt-in `IdempotencyGuard` deduplicates by key (TTL `idempotencyTtl`).
- **Single-instance lock** — `SingleInstanceLock` uses an ownership token so a release never frees a
  lock held by a different owner; contention surfaces as a failed result that the worker requeues.
- **Circuit breaker & rate limit** — `jobs:queue:work` skips a failing backend
  (`circuitBreakerThreshold` / `circuitBreakerCooldown`) and honours `queueRateLimits`.
- **Graceful shutdown** — SIGTERM/SIGINT finish the current cycle and exit; the in-flight job is not
  aborted mid-run.

See [Concurrency](concurrency.md) and [Retries](RETRIES.md).

---

## Security

- **Envelope signing** — `EnvelopeFactory::toWire()` signs the immutable identity fields with
  HMAC-SHA256 at enqueue; the worker rejects tampered/forged messages. Key resolution:
  `Config\Jobs::$signingKey` → `env('JOBS_SIGNING_KEY')` → Encryption key.
- **Per-queue handler allowlist** — `HandlerRegistry::resolveForQueue()` refuses handler keys not
  listed for a queue (`Config\Jobs::$queueHandlers`).
- **ShellHandler deny-by-default** — an empty `$allowedShellCommands` rejects everything; execution is
  via `proc_open` with an argv array (never `/bin/sh -c`).
- **EventHandler allowlist** — only `$allowedEvents` may be fired (empty = deny all).
- **UrlHandler anti-SSRF** — http/https only, private/reserved IPv4/IPv6 rejected, SSL verification
  forced, redirects disabled.

See [Security](security.md).

---

## Extending the architecture

1. **Custom handler** — implement `JobHandlerInterface` (or extend `AbstractJobHandler` /
   `TypedJobHandler`) and register the key in `Config\Jobs::$handlers`. See [Handlers](handlers.md).
2. **Custom backend** — implement `QueueBackend` and register it in `Config\Jobs::$backends`. See
   [Queues & Workers](QUEUES.md).
3. **Custom retry policy** — implement `RetryPolicy` and inject it into `QueueWorker`. See
   [Retries](RETRIES.md).
4. **Metrics** — implement `MetricsCollectorInterface` and set `Config\Jobs::$metricsCollector`. See
   [Configuration](CONFIGURATION.md).
