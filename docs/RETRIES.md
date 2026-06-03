# Retries & Backoff

In v3 retries are driven entirely by the **worker** and the **queue backend** — never by a blocking
loop inside the runtime. A job runs **one attempt** per delivery; on failure the worker either
requeues it with a backoff delay (`nack(delay)`) or hands it to the dead-letter path
(`abandon()`).

## The model

`QueueWorker::processOnce()` executes exactly one attempt and then decides:

- **Success** → `ack(lease)`. The message is removed permanently.
- **Failure, retries remaining** → `nack(lease, delay)`. The backend requeues the message; the delay
  applies real backoff (Redis ZSET / database `available_at` / native release-with-delay).
- **Failure, retries exhausted** → `abandon(lease)`. The backend settles the message via its native
  mechanism (Beanstalk buries, Database marks `failed`, Redis/Sync drop it) and a `critical` log is
  written so it cannot loop. See [Dead Letter Queue](#dead-letter-queue).

There is **no blocking `sleep()`** in the worker between the attempt and the requeue — the delay
lives in the backend, so the worker is free to process other jobs.

## Total runs = `maxRetries + 1`

`maxRetries` counts **retries**, not total executions. The envelope carries a 0-based `attempts`
counter (completed runs before the current one):

| `maxRetries` | Total executions |
|--------------|------------------|
| `0` (default) | 1 (no retries) |
| `1` | 2 |
| `2` | 3 |
| `3` | 4 |

The worker requeues while `attempts < maxRetries`; once `attempts == maxRetries` and the attempt
fails, it abandons. This single-attempt-per-fetch design removes the legacy double-retry where a
coordinator loop and a requeue helper each consumed `maxRetries`.

Set it per job:

```php
use Daycry\Jobs\Jobs;

Jobs::define('command', 'app:sync-stripe')
    ->queue('integrations')
    ->maxRetries(5) // up to 6 total executions
    ->dispatch();
```

## RetryPolicyFixed

A single class implements all strategies. The worker constructs it from `Config\Jobs` and asks for
the delay before the **next** attempt:

```php
use Daycry\Jobs\Execution\RetryPolicyFixed;

$policy = new RetryPolicyFixed(
    base: 5,
    strategy: 'exponential',
    multiplier: 2.0,
    max: 300,
    jitter: true,
);

$delay = $policy->computeDelay($attempt); // seconds; $attempt is 1-based
```

`computeDelay($attempt)` returns `0` when `$attempt <= 1` (no pre-delay before the first run).

## Strategies

Configured via `Config\Jobs::$retryBackoffStrategy`:

| Strategy | Behaviour |
|----------|-----------|
| `none` (default) | Always returns `0` — immediate requeue, no backoff. |
| `fixed` | Constant delay equal to `retryBackoffBase`. |
| `exponential` | `delay = base * multiplier^(attempt-2)`, capped at `retryBackoffMax`, with optional jitter. |

### Parameters

| Setting | Used by | Description |
|---------|---------|-------------|
| `retryBackoffBase` | fixed, exponential | Baseline seconds; equals the first retry delay. |
| `retryBackoffMultiplier` | exponential | Growth factor between attempts. |
| `retryBackoffMax` | exponential | Upper cap on any computed delay. |
| `retryBackoffJitter` | exponential | Add ±15% randomness to reduce thundering herd. |

### Exponential formula

```
delay = base * multiplier^(attempt - 2)   (clamped to max)
```

The exponent is `attempt - 2`, so the **first retry** (attempt 2) delay equals `base`:

```
base=5, multiplier=2, max=300:
computeDelay(1) -> 0s   (first run, no pre-delay)
computeDelay(2) -> 5s   (5 * 2^0)
computeDelay(3) -> 10s  (5 * 2^1)
computeDelay(4) -> 20s  (5 * 2^2)
computeDelay(5) -> 40s  (5 * 2^3)
```

With jitter enabled the result varies by ±15%. Delays above `max` are clamped.

## How the delay is applied per backend

The worker passes the computed delay to `QueueBackend::nack($lease, $delay)`. Each backend honours it
differently, but the observable effect is the same: the message becomes fetchable again only after
the delay elapses, and its `attempts` counter is incremented.

| Backend | How `nack(delay)` applies backoff |
|---------|-----------------------------------|
| `database` | `requeueInPlace()` updates the **same row** with `attempts + 1` and `available_at = now + delay`; the claim query only fetches rows whose `available_at`/`schedule` is due. No orphan rows. |
| `redis` | Re-serialises the payload with `attempts + 1`. With a delay it is added to the `{q}-delayed` ZSET (score = due timestamp) and promoted to the waiting list when due; with no delay it is `LPUSH`ed straight back to `{q}-waiting`. |
| `beanstalk` | beanstalkd's native `release` cannot mutate the job body, so the backend deletes the reserved job and `put`s a fresh copy with `attempts + 1` and the delay as the put-delay. |
| `serviceBus` | Service Bus has no in-place unlock-with-delay, so the backend enqueues a fresh copy (setting `ScheduledEnqueueTimeUtc` when a delay is requested) and settles the original lock. |
| `sync` | No-op (`nack()` returns `true`). The Sync backend runs jobs inline at `enqueue()` time, so there is nothing to requeue. |

> **Note:** Because `nack()` (re)serialises the wire payload, the **mutable** `attempts`/`schedule`
> fields change on every requeue. The HMAC signature deliberately excludes those fields, so a
> requeued message still verifies. See [Security](security.md).

## Dead Letter Queue

When retries are exhausted the worker calls `backend->abandon(lease)`, which settles the message
via the **backend's own native mechanism** — there is no shared dead-letter routing:

| Backend | What `abandon()` does |
|---------|-----------------------|
| `beanstalk` | Buries the job onto beanstalkd's **own** buried list (its native DLQ), not the queue named in `$deadLetterQueue`. |
| `database` | Marks the row `status = 'failed'` so it is retained for audit but never re-fetched. |
| `redis` | Drops the message from the processing set (it is removed). |
| `serviceBus` | Settles (dead-letters) the original lock via the broker. |
| `sync` | No-op (returns `true`). |

In every case a `critical` log entry records the permanent failure with the last error.

> **Important:** The worker does **not** read `Config\Jobs::$deadLetterQueue`. Setting it does *not*
> cause the worker to enqueue failed payloads onto a named queue. That config is consumed only by the
> opt-in `Daycry\Jobs\Libraries\DeadLetterQueue::store()` helper, which you must call yourself if you
> want to forward a failed payload to a dedicated DLQ:

```php
use Daycry\Jobs\Libraries\DeadLetterQueue;

// Opt-in, app-level. Reads Config\Jobs::$deadLetterQueue and re-enqueues the payload there.
(new DeadLetterQueue())->store($payload, $handler, $reason, $attempts);
```

```php
// In Config\Jobs — only the DeadLetterQueue helper above honours this.
public ?string $deadLetterQueue = 'failed_jobs';
```

## At-least-once delivery

Every persistent backend is **at-least-once**: a crashed worker's lease is recovered by
`reapExpired()` (see `jobs:queue:reap`) and the message is redelivered. Handlers should therefore be
**idempotent**. For exactly-once-ish semantics, opt in to deduplication:

```php
Jobs::define('command', 'app:close-month')
    ->queue('reports')
    ->idempotencyKey('close-month-2026-06')
    ->dispatch();
```

`IdempotencyGuard` marks the key in the cache (TTL `Config\Jobs::$idempotencyTtl`). A second delivery
of the same key is acked **without** running the handler again. The check-then-set is best-effort
and only atomic on backends that support `SET NX` (e.g. Redis).

For the full attempt-counter semantics see [Attempts](ATTEMPTS.md), and for the trust-boundary view
of replays and tampering see [Security](security.md).

## Metrics

The worker emits per-cycle counters (when a metrics collector is configured):

| Counter | Meaning |
|---------|---------|
| `jobs_fetched` | A message was leased. |
| `jobs_succeeded` | Attempt succeeded; message acked. |
| `jobs_failed` | Attempt failed (before deciding requeue vs dead-letter). |
| `jobs_requeued` | Failure that resulted in a requeue with backoff. |
| `jobs_failed_permanently` | Retries exhausted; message dead-lettered. |
| `jobs_skipped_idempotent` | Duplicate idempotency key; acked without running. |
| `jobs_rejected_signature` | Message rejected for an invalid/missing HMAC signature. |
