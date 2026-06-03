# Exception Handling

v3 turns failures into data, not crashes. A handler may throw any `Throwable`; the runtime catches
it, records a failed `ExecutionResult`, and the worker decides whether to requeue with backoff or
dead-letter the message. The worker process itself keeps running.

## Where exceptions are handled

| Layer | Class | Responsibility |
|-------|-------|----------------|
| Single attempt | `Daycry\Jobs\Execution\JobRuntime` | Run the handler, capture output, apply the timeout, turn any `Throwable` into a failed `ExecutionResult`. |
| Retry decision | `Daycry\Jobs\Worker\QueueWorker` | Inspect the result; ack on success, `nack(delay)` with retries left, `abandon()` when exhausted. |
| Timeout | `Daycry\Jobs\Execution\Timeout` | Throw `JobException::forJobTimeout()` at the deadline (SIGALRM, interrupts CPU-bound code) or via a soft post-hoc check without `pcntl`. |

**Key principle:** exceptions never crash the worker. They are caught at the execution boundary,
recorded, and routed through the same retry/dead-letter path as a logical failure.

## Single attempt — `JobRuntime`

`JobRuntime::run(JobDefinition $def, JobContext $ctx): ExecutionResult` wraps the whole attempt:

```php
try {
    $handler = $this->registry->resolveForQueue($definition->handler, $context->queue ?? 'default');
} catch (Throwable $e) {
    // Handler key unknown, or blocked by the per-queue allowlist.
    return new ExecutionResult(false, null, $e->getMessage(), $start, microtime(true));
}

try {
    $handler->beforeRun($context);
    ob_start();
    $returned = $this->timeout->run($timeoutSeconds, static fn () => $handler->handle($context), $label);
    $result   = new ExecutionResult(true, $this->normalizeOutput($returned, ob_get_clean()), null, $start, microtime(true), $handler::class);
    $this->safeAfterRun($handler, $context, $result);

    return $result;
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    $result = new ExecutionResult(false, null, $e->getMessage(), $start, microtime(true), $handler::class);
    $this->safeAfterRun($handler, $context, $result);

    return $result;
}
```

What is caught:

| Source | Result |
|--------|--------|
| Unknown handler key (`forInvalidJob`) | failed `ExecutionResult` |
| Handler not allowed on this queue | failed `ExecutionResult` |
| `beforeRun()` / `handle()` throws | failed `ExecutionResult` with the message |
| Timeout exceeded | failed `ExecutionResult` (`JobException::forJobTimeout`) |
| Any `Throwable` (`Error`, `TypeError`, ...) | failed `ExecutionResult` |

`afterRun()` runs through `safeAfterRun()`: an exception there is swallowed and never changes the
recorded outcome.

## Retry decision — `QueueWorker`

The worker reads `ExecutionResult::$success` and acts:

- success → `ack()`
- failure, `attempts < maxRetries` → `nack($lease, $delay)` (backend requeues with backoff)
- failure, retries exhausted → `abandon()` (dead-letter) plus a `critical` log line

See [Retries](RETRIES.md) for the backoff model and [Attempts](ATTEMPTS.md) for the counter.

## Built-in exceptions

### `JobException` (`Daycry\Jobs\Exceptions\JobException`)

Named constructors for definition/validation/execution errors:

```php
JobException::forInvalidJob('unknown_handler');
JobException::validationError('CommandHandler payload must be a non-empty string.');
JobException::forShellCommandNotAllowed('/usr/bin/rm');
JobException::forShellCommandsNotConfigured();      // deny-by-default ShellHandler
JobException::forEventNotAllowed('user.deleted');   // not in $allowedEvents
JobException::forInvalidMethod('TRACE');            // UrlHandler method allowlist
JobException::forInvalidPriority(99);
JobException::forJobTimeout('app:report', 120);
JobException::forRateLimitExceeded('default', 50);
```

### `QueueException` (`Daycry\Jobs\Exceptions\QueueException`)

Backend/queue resolution errors:

```php
QueueException::forInvalidWorker('does-not-exist'); // unknown $backends key
QueueException::forInvalidQueue('nope');
QueueException::forInvalidConnection('redis refused');
```

`QueueException::forInvalidWorker()` is thrown by `BackendFactory::make()` when the requested backend
name is absent from `Config\Jobs::$backends` or does not implement `QueueBackend`.

## Security-related rejections

Some failures are intentional refusals rather than bugs:

- **Invalid signature** — when `verifyEnvelopeSignature` is on and the HMAC `_sig` does not verify,
  the worker logs `critical` and `abandon`s the message (`WorkerResult` status `rejected`). It is not
  retried.
- **Blocked handler** — a handler key not allowed on the queue raises a validation error inside
  `JobRuntime`, recorded as a normal failed attempt.
- **Shell / event refusals** — `ShellHandler` (deny-by-default) and `EventHandler` (empty allowlist)
  throw `JobException` before doing any work.

## Writing handlers

Throw freely — the runtime records the message and the worker handles retries. Prefer specific,
descriptive exceptions:

```php
use Daycry\Jobs\Handlers\AbstractJobHandler;
use Daycry\Jobs\Execution\JobContext;
use RuntimeException;

final class ImportUsers extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        if (! isset($payload['file'])) {
            throw new RuntimeException('ImportUsers payload missing "file".');
        }

        // ... business logic ...
        return 'imported';
    }
}
```

Guidelines:

- Make handlers **idempotent** (delivery is at-least-once).
- Clean up resources with `try/finally` so a thrown exception still releases files/connections.
- Tune `maxRetries` per job: few for cheap work, more for flaky external calls, `0` to fail fast.

## Running the worker under a supervisor

Exceptions inside a handler never stop the worker, but OOM, `kill -9`, or a backend outage can. Run
it under a process supervisor so it restarts:

```ini
[program:jobs-worker]
command=php spark jobs:queue:work default
autorestart=true
stopsignal=TERM
stderr_logfile=/var/log/jobs-worker.err.log
```

SIGTERM/SIGINT trigger a graceful shutdown: the current cycle finishes and the worker exits.
