# Attempts Semantics

Attempts represent completed execution cycles of a job. They are not merely "failures" or "retries"; success also advances the counter.

## Life Cycle
1. Enqueued job starts with `attempts = 0` (never executed).
2. When the worker finishes running the job (success OR failure) the counter is incremented exactly once.
3. If the job failed and is requeued the increment remains (e.g. after first failed run attempts=1).
4. The next execution will increment to 2, etc.

## Why This Model?
- Retry policies need a consistent monotonic counter to compute backoff.
- Logging & analytics benefit from knowing how many full cycles a job has consumed.
- Avoids ambiguity of whether `attempt=0` means already processed or still pristine.

## Accessing Attempts
```php
$attempt = $job->getAttempt();
```
You typically only read this in retry policy evaluators or logging.

## Requeue Flow
Requeue logic is centralized in `RequeueHelper::finalize()` ensuring a single authoritative increment per cycle and preventing duplicate counting across queue backends.

## Custom Retry Constraints
Implement a policy that decides max attempts:
```php
if ($job->getAttempt() >= 5) {
    // mark permanently failed, do not requeue
}
```

## Edge Cases
- If a job is removed/requeued manually bypassing `RequeueHelper::finalize()`, attempts will NOT increment; this path is discouraged.
- Partial executions aborted before finalization should not increment (ensures only completed cycles count).

## Relation to Backoff
Backoff delay may use formulae referencing `attempt` (e.g. exponential: `delay = base * multiplier^(attempt-1)`). With this semantics the first finished run (attempt=1) leads to a first retry delay derived from the base.
