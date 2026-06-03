# Attempts Semantics

The attempts counter records how many times a job has already been executed. It is carried on the
queue envelope as `attempts` and surfaced to handlers via `JobContext::$attempt`.

## Where it lives

- **Envelope (`attempts`)** — a **0-based** counter of *completed runs before the current one*. A
  freshly enqueued message has `attempts = 0`. Each requeue (`nack`) advances it by one.
- **`JobContext::$attempt`** — a **1-based** view for the handler: it equals `envelope->attempts + 1`,
  so the very first execution sees `attempt = 1`.

`JobDefinition` describes the retry budget (`maxRetries`); the live attempt count travels on the
envelope, not on the definition.

## Life cycle

1. A job is enqueued with `attempts = 0` (never executed).
2. The worker fetches the message and runs **one** attempt. The handler sees
   `JobContext::$attempt = attempts + 1`.
3. On **success**, the message is acked — the counter is irrelevant after that.
4. On **failure with retries left**, the worker `nack`s the message; the backend requeues it with
   `attempts` incremented, so the next delivery sees a higher `attempt`.
5. When `attempts == maxRetries` and the attempt fails, the worker `abandon`s the message
   (dead-letter). Total executions therefore equal `maxRetries + 1`.

## Why this model

- The worker (not the runtime) owns the retry decision, so the counter advances exactly once per
  delivery — there is no double counting across backends.
- Backoff strategies need a monotonic counter to compute the next delay; `RetryPolicy::computeDelay()`
  is called with the next attempt number.
- Handlers can branch on `attempt` (e.g. log differently on the final try) without knowing anything
  about the queue.

## Reading the attempt in a handler

```php
use Daycry\Jobs\Handlers\AbstractJobHandler;
use Daycry\Jobs\Execution\JobContext;

final class SyncStripe extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        if ($ctx->attempt > 1) {
            log_message('warning', "Retry #{$ctx->attempt} for {$ctx->name}");
        }

        // ... business logic ...
        return 'ok';
    }
}
```

## Relation to backoff

The worker computes the requeue delay from the **next** attempt number:

```
delay = RetryPolicy::computeDelay(attemptIndex + 2)
```

where `attemptIndex` is the 0-based envelope `attempts` for the run that just failed. With the
exponential strategy the first retry delay equals `retryBackoffBase`. See [Retries](RETRIES.md).

## Relation to delivery guarantees

Delivery is **at-least-once**: if a worker crashes mid-run, `reapExpired()` (via `jobs:queue:reap`)
returns the message for redelivery. The redelivered message keeps its `attempts`, so a crash does
not silently burn a retry — but it does mean the same `attempt` may run more than once. Make handlers
idempotent, and use `idempotencyKey()` when a duplicate run must be prevented.
