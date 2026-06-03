# Architecture Overview

v3 is a single, layered architecture. Each layer has one responsibility and communicates with the
next through small, immutable value objects. There is no mutable god-object: definitions describe
work, handlers contain only business logic, and the worker orchestrates a single attempt per
delivery.

## Layers

| Layer | Namespace | Responsibility |
|-------|-----------|----------------|
| Definition | `Daycry\Jobs\Definition` | Describe a job. `JobBuilder` (fluent, mutable accumulator) produces an immutable `JobDefinition` value object. |
| Handlers | `Daycry\Jobs\Handlers` | Business logic only. `JobHandlerInterface::handle(JobContext)`; `HandlerRegistry` resolves keys and enforces the per-queue allowlist. |
| Queues | `Daycry\Jobs\Queues` | Transport. One `QueueBackend` contract with lease semantics; `JobEnvelope` is the wire message, `JobLease` is an in-flight reservation. `EnvelopeFactory` builds and signs the wire payload; `BackendFactory` resolves backends from config. |
| Backends | `Daycry\Jobs\Queues\Backends` | Concrete transports: `Sync`, `Database`, `Redis`, `Beanstalk`, `ServiceBus`. |
| Signing | `Daycry\Jobs\Queues\Signing` | `EnvelopeSigner` — HMAC-SHA256 sign/verify with constant-time comparison. |
| Execution | `Daycry\Jobs\Execution` | Run a single attempt. `JobRuntime`, `JobContext`, `ExecutionResult`, `Timeout`, `RetryPolicy`/`RetryPolicyFixed`, `IdempotencyGuard`, `SingleInstanceLock`. |
| Worker | `Daycry\Jobs\Worker` | `QueueWorker` integrates the pipeline: fetch → verify → run one attempt → ack/nack/abandon. `WorkerResult` reports the outcome. |
| Cron | `Daycry\Jobs\Cron` | `Scheduler` (fluent registry of `JobBuilder`s) and `CronRunner` (evaluate due definitions; enqueue or run inline). |
| Config | `Daycry\Jobs\Config` | `Config\Jobs` — handler/backend maps, security allowlists, retry/timeout settings, and `init()` to register scheduled jobs. |

## Core value objects

- **`JobDefinition`** (immutable): *what the job is* — handler key, payload, scheduling, retry
  policy. `withXxx()` returns a new instance.
- **`JobEnvelope`** / wire payload: *how the definition travels* through a queue. The wire object is
  `{ job, payload, queue, priority, maxRetries, attempts, name, identifier, schedule, _sig }`.
- **`JobLease`**: a reservation on an in-flight message — `token` (backend handle), `ownerToken`
  (minted by the worker so a reaped-then-reassigned message cannot be acked by the previous owner),
  and `expiresAt` (visibility deadline).
- **`JobContext`** (immutable): *what a handler sees* — `payload`, `name`, `queue`, `attempt`,
  `meta`. Handlers never receive scheduling or queue state.
- **`ExecutionResult`**: the outcome of one handler run — `success`, `output`, `error`, timing,
  resolved handler class.

## Queue pipeline (worker)

`QueueWorker::processOnce($queue)` drives one message end to end:

1. **fetch** — `backend->fetch($queue)` leases one ready message, or returns `null` (empty).
2. **verify** — if `verifyEnvelopeSignature` is on and a key is configured, the HMAC `_sig` is
   verified over the canonical identity JSON with `hash_equals()`. A tampered/forged message is
   `abandon()`ed (`rejected`).
3. **idempotency** — if the envelope carries an `idempotencyKey`, `IdempotencyGuard` short-circuits a
   duplicate: `ack()` without running (`skipped-idempotent`).
4. **run one attempt** — `JobRuntime::run(definition, context)` resolves the handler (enforcing the
   per-queue allowlist), applies the timeout, captures output, and returns an `ExecutionResult`.
5. **decide**:
   - success → `ack()` (`acked`),
   - failure with retries left → `nack($lease, $delay)` where the delay comes from `RetryPolicy`
     (`requeued`),
   - retries exhausted → `abandon()` → dead-letter (`dead-lettered`).

The retry decision lives in the worker, not the runtime. The runtime runs **once** and never sleeps;
backoff is realised by the backend honouring `nack(delay)`. See [Retries](RETRIES.md).

```
fetch ──► verify signature ──► idempotency guard ──► run ONE attempt
                                                          │
                       ┌──────────────────┬──────────────┴───────────────┐
                       ▼                  ▼                               ▼
                   success            failure (retries left)        retries exhausted
                       │                  │                               │
                     ack()           nack(delay)                      abandon()
                                  (backend requeues)               (dead-letter / drop)
```

## Cron pipeline

1. `jobs:cronjob:run` builds a `Scheduler` and calls `Config\Jobs::init($scheduler)`.
2. `CronRunner` walks the definitions in **topological order** of `dependsOn()`.
3. For each definition it skips disabled jobs (`enabled()`) and jobs outside the current
   `environments()`, then evaluates the cron expression against "now".
4. A due definition with a `queue` is **enqueued** via `BackendFactory`; otherwise it runs **inline**
   through `JobRuntime` (one attempt). The runner never sleeps between jobs.

## Backends

A single contract, with at-least-once delivery on every persistent backend:

```php
interface QueueBackend
{
    public function enqueue(JobDefinition $definition): string;
    public function fetch(string $queue): ?JobLease;
    public function ack(JobLease $lease): bool;
    public function nack(JobLease $lease, ?int $delaySeconds = null): bool;
    public function abandon(JobLease $lease): bool;
    public function reapExpired(string $queue, int $visibilityTimeout): int;
}
```

| Backend | Notes |
|---------|-------|
| `SyncBackend` | `enqueue()` runs the job inline via `JobRuntime` and returns a synthetic id; `fetch()` is always `null`, lease verbs are no-ops. Ideal for tests/CLI. |
| `DatabaseBackend` | Atomic reservation; `reapExpired()` returns `in_progress` rows past the visibility timeout to `pending`; requeue is an in-place `UPDATE` (no orphan rows). |
| `RedisBackend` | Reliable-queue pattern (waiting/processing) with owner tokens; `nack(delay)` uses a delayed ZSET; `reapExpired()` recovers stalled leases. |
| `BeanstalkBackend` | Native release-with-delay and TTR; recovers stalled jobs natively. |
| `ServiceBusBackend` | Peek-lock with `serviceBusLockTimeout`; broker redelivers on lock expiry. |

`reapExpired()` is exercised by `jobs:queue:reap` for Database/Redis; Beanstalk and Service Bus
recover natively.

## Execution & resilience

- **One attempt per fetch** — `JobRuntime` runs the handler exactly once; the worker owns the retry
  decision.
- **Timeout that interrupts** — `Timeout` installs a SIGALRM handler that *throws* (with
  `pcntl_async_signals(true)`), so even CPU-bound code is interrupted at the deadline. Without
  `pcntl` it degrades to a documented soft check.
- **Idempotency** — opt-in `IdempotencyGuard` deduplicates by key (TTL `idempotencyTtl`).
- **Single-instance lock** — `SingleInstanceLock` uses an ownership token so a release never frees a
  lock held by a different owner.
- **Circuit breaker & rate limit** — `jobs:queue:work` skips a failing backend
  (`circuitBreakerThreshold`/`circuitBreakerCooldown`) and honours `queueRateLimits`.
- **Graceful shutdown** — SIGTERM/SIGINT finish the current cycle and exit; the in-flight job is not
  aborted mid-run.

## Security

- **Envelope signing** — `EnvelopeFactory::toWire()` signs the immutable identity fields with
  HMAC-SHA256 at enqueue; the worker rejects tampered/forged messages. Key resolution:
  `Config\Jobs::$signingKey` → `env('JOBS_SIGNING_KEY')` → Encryption key.
- **Per-queue handler allowlist** — `HandlerRegistry::resolveForQueue()` refuses handler keys not
  listed for a queue (`Config\Jobs::$queueHandlers`).
- **ShellHandler deny-by-default** — empty `$allowedShellCommands` rejects everything; execution is
  via `proc_open` with argv (never `/bin/sh -c`).
- **EventHandler allowlist** — only `$allowedEvents` may be fired (empty = deny).
- **UrlHandler anti-SSRF** — http/https only, private/reserved IPs rejected, SSL verification forced,
  redirects disabled.

## Extending

1. **Custom handler** — implement `JobHandlerInterface` (or extend `AbstractJobHandler` /
   `TypedJobHandler`) and register the key in `Config\Jobs::$handlers`.
2. **Custom backend** — implement `QueueBackend` and register it in `Config\Jobs::$backends`.
3. **Custom retry policy** — implement `RetryPolicy` and inject it into `QueueWorker`.
4. **Metrics** — implement `MetricsCollectorInterface` and set `Config\Jobs::$metricsCollector`.
