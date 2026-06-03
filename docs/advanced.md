# Advanced

This page covers custom handlers, the execution model, idempotency, envelope signing and the
single-instance lock.

## Custom handlers

A v3 handler contains **only business logic**. It implements
`Daycry\Jobs\Handlers\JobHandlerInterface` and receives an immutable `JobContext` — it never carries
scheduling or queue state.

```php
namespace Daycry\Jobs\Handlers;

interface JobHandlerInterface
{
    public function handle(JobContext $ctx): mixed;        // business logic; return value is recorded
    public function beforeRun(JobContext $ctx): void;      // optional hook before handle()
    public function afterRun(JobContext $ctx, ExecutionResult $result): void; // optional, always runs
}
```

Extend `AbstractJobHandler` so you only have to implement `handle()`; the lifecycle hooks default to
no-ops. `afterRun()` runs regardless of success and any exception it throws is swallowed — it can
never change the recorded outcome.

`JobContext` (immutable) exposes everything a handler needs:

```php
final readonly class JobContext
{
    public mixed $payload;     // the payload you dispatched
    public ?string $name;      // logical job name (for logs/metrics)
    public ?string $queue;     // queue the job came from
    public int $attempt;       // 1-based attempt counter
    public array $meta;        // free-form metadata
}
```

Throwing any `Throwable` from `handle()` signals failure to the runtime; a scalar/array return value
is normalised to a string/JSON output.

### Example

```php
<?php

namespace App\Jobs;

use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\AbstractJobHandler;

final class SendInvoiceHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $invoiceId = $ctx->payload['invoice_id'] ?? null;
        if ($invoiceId === null) {
            throw new \InvalidArgumentException('invoice_id is required');
        }

        // ... business logic ...

        return ['sent' => true, 'invoice_id' => $invoiceId];
    }
}
```

Register the handler under a key in `Config\Jobs::$handlers`, then dispatch by that key:

```php
// Config\Jobs
public array $handlers = [
    // built-in keys: command, shell, closure, event, url
    'send-invoice' => \App\Jobs\SendInvoiceHandler::class,
];
```

```php
use Daycry\Jobs\Jobs;

Jobs::define('send-invoice', ['invoice_id' => 42])
    ->queue('billing')
    ->maxRetries(3)
    ->dispatch();
```

The registry validates that the configured class implements `JobHandlerInterface` and enforces the
per-queue allowlist (see [Per-queue handler allowlist](#per-queue-handler-allowlist)).

### Typed handlers

`TypedJobHandler` hydrates a typed DTO from `$ctx->payload` (an array, `stdClass`, or JSON string) and
passes it to `run()`, so your business logic works against a real object rather than `mixed`.

```php
<?php

namespace App\Jobs;

use Daycry\Jobs\Handlers\TypedJobHandler;

final class ImportRequest
{
    public function __construct(
        public string $source,
        public int $batchSize = 100,
    ) {
    }
}

final class ProcessImport extends TypedJobHandler
{
    public function payloadType(): string
    {
        return ImportRequest::class;
    }

    protected function run(object $payload): mixed
    {
        /** @var ImportRequest $payload */
        return "importing {$payload->source} in batches of {$payload->batchSize}";
    }
}
```

Hydration prefers constructor parameters (matched by name), filling defaults or null where the
payload omits a value, then sets any remaining matching public (non-readonly) properties. A required
constructor parameter with no payload value raises a validation error.

```php
Jobs::define('process-import', ['source' => 's3://bucket/file.csv', 'batchSize' => 250])
    ->queue('imports')
    ->dispatch();
```

## Execution model and callbacks

Each fetched message runs through `JobRuntime` **exactly once**. The runtime resolves the handler
(applying the per-queue allowlist), runs `beforeRun` -> `handle` -> `afterRun`, captures output, and
applies a real timeout. It never loops or sleeps for retries — the worker owns the retry decision and
requeues with backoff via `QueueBackend::nack($lease, $delay)`.

> **No serializable callbacks/middleware in v3.** There is no `then()`/`catch()`/`finally()` chaining
> or `setCallbackJob()` API. A closure cannot survive serialisation to a remote backend, so the only
> closure-capable path is the `sync` backend. To model a workflow, dispatch the next job from inside
> your handler:

```php
final class ProcessPaymentHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        // ... process payment ...

        // Chain the next step explicitly by enqueueing it yourself.
        Jobs::define('command', 'app:send-invoice')->queue('billing')->dispatch();

        return 'paid';
    }
}
```

The `closure` handler exists for inline/`sync` use (and inline cron jobs); enqueuing a closure to a
persistent backend is rejected because it cannot be serialised.

## Timeout

A per-attempt timeout interrupts work that runs too long. Set it per job with `timeout()` (seconds),
or globally with `Config\Jobs::$defaultTimeout` (null = unlimited). Where the `pcntl` extension is
available the timeout raises and interrupts the job; otherwise a documented soft fallback applies.

```php
Jobs::define('command', 'app:report')->timeout(120)->queue('reports')->dispatch();
```

## Idempotency

Delivery is **at-least-once**, so the same message may be delivered more than once (e.g. after a
crashed-worker reap). Opt in to deduplication with `idempotencyKey()`:

```php
Jobs::define('command', 'app:report')
    ->queue('reports')
    ->idempotencyKey('report-2026-06-03')
    ->dispatch();
```

Before running, the worker consults `IdempotencyGuard`: if the key was already processed it
acknowledges the message **without re-executing** the handler. Keys are stored in the cache with a TTL
of `Config\Jobs::$idempotencyTtl` (default 86400s).

Caveat: the check-then-set is best-effort and only strictly atomic on caches with native `SET NX`
(e.g. Redis). Two workers racing on the same key could both observe a miss, so keep your handlers
idempotent regardless.

## Envelope signing

Queue envelopes are signed with **HMAC-SHA256** at enqueue time. When `verifyEnvelopeSignature` is
true (the default) and a signing key is configured, the worker rejects (abandons) any message whose
signature is missing or invalid — defeating tampered or forged messages that try to choose the
handler (anti-RCE).

Key resolution order:

1. `Config\Jobs::$signingKey`
2. `env('JOBS_SIGNING_KEY')`
3. the CodeIgniter `Config\Encryption::$key`

If no key resolves, the signer runs in an insecure pass-through mode (logged as critical). **Set a
real signing key in production**, e.g. in `.env`:

```ini
JOBS_SIGNING_KEY = "a-long-random-secret"
```

Set `verifyEnvelopeSignature = false` only for fully trusted, private backends.

## Per-queue handler allowlist

Restrict which handler keys a queue may invoke with `Config\Jobs::$queueHandlers`. A queue absent
from the map (or with no entry) imposes no restriction — **set it explicitly in production** so a
remote queue cannot invoke `shell` or `command`:

```php
public array $queueHandlers = [
    'reports' => ['command'],
    'web'     => ['url', 'event'],
];
```

The runtime throws when a queue tries to run a handler that is not on its list.

### Handler security defaults

- **shell** — deny-by-default. With an empty `Config\Jobs::$allowedShellCommands` (and
  `allowAllShellCommands = false`) execution is refused. Configure the allowlist with absolute paths,
  or set `allowAllShellCommands = true` to opt out (insecure). Commands run via `proc_open` with an
  argv array, never through `/bin/sh -c`, removing the shell-metacharacter attack surface.
- **event** — only events listed in `Config\Jobs::$allowedEvents` may fire; an empty list denies all.
- **url** — SSRF-hardened: http/https only, private/reserved IPv4/IPv6 targets rejected (A/AAAA
  records resolved), SSL verification forced on, and redirects disabled.

## Single instance

`singleInstance()` marks a definition as non-overlapping. The package provides
`Daycry\Jobs\Execution\SingleInstanceLock`, a cache-backed lock keyed by job name that records an
ownership token:

```php
use Daycry\Jobs\Execution\SingleInstanceLock;

$lock  = new SingleInstanceLock();
$owner = bin2hex(random_bytes(8));

if ($lock->acquire('nightly-report', $owner, ttl: 3600)) {
    try {
        // ... do work ...
    } finally {
        $lock->release('nightly-report', $owner); // only the owner can release
    }
}
```

`acquire()` returns false when the lock is already held by a **different** owner; `release()` only
frees a lock held by the matching owner, so a reassigned lock is never freed by a stale holder. The
lock is best-effort: it is strictly atomic only on caches with native `SET NX`.

## Metrics

Provide a custom collector implementing `Daycry\Jobs\Metrics\MetricsCollectorInterface` and set
`Config\Jobs::$metricsCollector`. The default in-memory collector is fine for local/dev but not for
production scraping. See [Metrics & Monitoring](metrics-monitoring.md).
