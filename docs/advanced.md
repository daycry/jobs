# Advanced Topics

This page covers the topics that don't belong on the [Jobs](jobs.md) or [Handlers](handlers.md)
pages: the v3 model for callbacks/middleware (and how to model workflows without serialisable
callbacks), idempotency in depth, envelope signing, the single-instance lock, and extensibility —
writing advanced custom handlers and your own `QueueBackend`.

- [Custom handlers](#custom-handlers)
- [Execution model and callbacks](#execution-model-and-callbacks)
- [Callbacks and middleware: the v3 model](#callbacks-and-middleware-the-v3-model)
- [Timeout](#timeout)
- [Idempotency in depth](#idempotency-in-depth)
- [Envelope signing](#envelope-signing)
- [Per-queue handler allowlist](#per-queue-handler-allowlist)
- [Single instance](#single-instance)
- [Extensibility: custom QueueBackend](#extensibility-custom-queuebackend)
- [Metrics](#metrics)

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
applies a real timeout. It never loops or sleeps for retries — the worker (`QueueWorker`) owns the
retry decision and requeues with backoff via `QueueBackend::nack($lease, $delay)`.

The worker's per-message pipeline is:

```text
fetch -> verify signature -> idempotency guard -> run ONE attempt -> ack / nack(backoff) / abandon
```

A job therefore runs **at most `maxRetries + 1` times**. On a failed attempt with retries remaining
the worker `nack`s with a computed backoff delay; once retries are exhausted it `abandon`s the lease
(dead-letter). See [Retries](RETRIES.md) and [Architecture](ARCHITECTURE.md) for the full flow.

## Callbacks and middleware: the v3 model

> **There are no serialisable callbacks or middleware in v3.** There is no `then()` / `catch()` /
> `finally()` chaining and no `setCallbackJob()` API. This is deliberate: a closure or anonymous
> callback cannot survive serialisation to a remote backend (Redis, Database, Beanstalk, Service
> Bus), so a "callback" model would only ever work on the `sync` backend and would silently break the
> moment you moved to a persistent queue.

The v3 pattern is **explicit chaining**: dispatch the next job from inside your handler. This keeps
every step independently enqueued, retryable, and observable.

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

### Modelling success / failure / finally

- **"then" (on success)** — enqueue the next job at the *end* of `handle()`. Because it is only
  reached when `handle()` returns normally, it runs only on success.
- **"catch" (on failure)** — enqueue a compensating job from a `try/catch` inside `handle()`, then
  re-throw so the attempt is still recorded as failed (and retried/dead-lettered per `maxRetries`).
- **"finally" (always)** — use the `afterRun($ctx, $result)` hook, which runs after every attempt
  regardless of outcome. Inspect `$result->success` to branch. Remember that exceptions thrown from
  `afterRun()` are swallowed and never change the recorded outcome.

```php
final class GenerateReportHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        try {
            $path = $this->buildReport($ctx->payload);
        } catch (\Throwable $e) {
            // "catch": enqueue a compensating/alerting job, then re-throw to fail the attempt.
            Jobs::define('command', 'app:alert-ops --report=failed')->queue('ops')->dispatch();

            throw $e;
        }

        // "then": only reached on success.
        Jobs::define('command', "app:publish-report --path={$path}")->queue('reports')->dispatch();

        return ['report' => $path];
    }

    public function afterRun(JobContext $ctx, ExecutionResult $result): void
    {
        // "finally": always runs, regardless of success. Exceptions here are swallowed.
        log_message('info', "report attempt {$ctx->attempt} success={$result->success}");
    }
}
```

> **Note:** Dispatching from inside a handler enqueues the next job through the configured backend
> like any other dispatch — it is not a special "continuation". If you need the chained job to run
> only after a delay, use `scheduledAt()` on it.

The `closure` handler exists for inline/`sync` use (and inline cron jobs); enqueuing a closure to a
persistent backend fails at enqueue time because a `Closure` cannot be JSON-encoded — it surfaces as a
JSON encoding exception from `EnvelopeFactory::toWire()` (there is no dedicated validation path that
rejects it earlier). So `closure` is only usable on the `sync` backend / inline cron.

## Timeout

A per-attempt timeout interrupts work that runs too long. Set it per job with `timeout()` (seconds),
or globally with `Config\Jobs::$defaultTimeout` (null = unlimited). Where the `pcntl` extension is
available the timeout raises and interrupts the job; otherwise a documented soft fallback applies.

```php
Jobs::define('command', 'app:report')->timeout(120)->queue('reports')->dispatch();
```

## Idempotency in depth

Delivery is **at-least-once** on every persistent backend, so the same message may be delivered more
than once — for example after a crashed-worker reap (`jobs:queue:reap`), a redelivered Service Bus
lock, or a retry. The builder exposes `idempotencyKey()` to opt in to deduplication:

```php
Jobs::define('command', 'app:report')
    ->queue('reports')
    ->idempotencyKey('report-2026-06-03')
    ->dispatch();
```

When `idempotencyKey()` is set, `EnvelopeFactory::toWire()` serialises it onto the envelope and
includes it in `canonicalJson()`, so the key is one of the **signed identity fields** — tampering
with it breaks signature verification. The worker reads `$wire->idempotencyKey`
(`QueueWorker::processOnce()`) and drives the guard described below end-to-end for builder-dispatched
jobs. The feature is opt-in; because delivery is at-least-once and the dedupe is best-effort under
crash/redelivery, keep your handlers idempotent on their own as well.

### How the guard works

When an idempotency key is present on the wire, the worker consults
`Daycry\Jobs\Execution\IdempotencyGuard` before running:

```php
final readonly class IdempotencyGuard
{
    public function firstRun(string $key, ?int $ttl = null): bool; // true on FIRST sighting; false if already seen
    public function forget(string $key): void;                     // clear a key so a controlled retry may run again
}
```

- The guard prefixes every key with `jobs_idem_` and stores it in the CodeIgniter **cache**.
- `firstRun()` returns `true` the first time it sees a key (registering it), and `false` thereafter.
- When the worker gets `false` (for a message that does carry a key), it **acks the message without
  executing the handler** and reports a `skipped-idempotent` result.

Keys live in the cache for `Config\Jobs::$idempotencyTtl` seconds (default `86400` = 24h). After the
TTL elapses the same key would run again — choose a TTL longer than the window in which a duplicate
could plausibly arrive.

### Choosing keys

A good key uniquely identifies the *logical* unit of work, independent of how many times it is
enqueued. For example `report-2026-06-03` (one report per day) or `invoice-{id}-emailed`. Avoid keys
derived from volatile data (timestamps, random ids) — they defeat deduplication.

### Forcing a re-run

`forget()` removes the mark so a controlled retry may run again:

```php
use Daycry\Jobs\Execution\IdempotencyGuard;

(new IdempotencyGuard())->forget('report-2026-06-03');
// The next message with that key will run instead of being skipped.
```

> **Warning:** The check-then-set is **best-effort** and only strictly atomic on caches with native
> `SET key value NX EX ttl` semantics (e.g. Redis). On a cache-agnostic driver two workers racing on
> the same key could both observe a miss and both run. Keep your handlers idempotent regardless — the
> guard is a strong optimisation, not a hard exactly-once guarantee.

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

## Extensibility: custom QueueBackend

Every backend implements the single `Daycry\Jobs\Queues\QueueBackend` contract, so you can add your
own (e.g. SQS, RabbitMQ) without touching the worker or the runtime. The contract is **stateless with
respect to the in-flight message**: `fetch()` hands the worker a `JobLease`, and the worker passes
that same lease back to `ack()` / `nack()` / `abandon()`.

```php
namespace Daycry\Jobs\Queues;

use Daycry\Jobs\Definition\JobDefinition;

interface QueueBackend
{
    public function enqueue(JobDefinition $definition): string;            // -> backend-assigned id
    public function fetch(string $queue): ?JobLease;                       // null when empty
    public function ack(JobLease $lease): bool;                            // processed OK -> remove
    public function nack(JobLease $lease, ?int $delaySeconds = null): bool; // failed -> redeliver (after delay)
    public function abandon(JobLease $lease): bool;                        // unprocessable -> DLQ / drop
    public function reapExpired(string $queue, int $visibilityTimeout): int; // reclaim crashed-worker leases
}
```

### Contract obligations

| Method | What a correct implementation must do |
|--------|---------------------------------------|
| `enqueue` | Serialise the definition with `EnvelopeFactory::toWire()` so the stored shape (including `_sig`) matches every other backend. Return a unique id. |
| `fetch` | Lease **one** ready message and return a `JobLease` carrying a fresh `ownerToken` and a visibility `expiresAt`. Return `null` when nothing is ready (after any blocking timeout). |
| `ack` | Permanently remove the leased message. Verify the lease's `ownerToken` so a reaped-then-reassigned message cannot be acked by a stale owner. |
| `nack` | Make the message eligible for redelivery, optionally after `$delaySeconds` (backoff). Persistent backends requeue in place and increment `attempts`. |
| `abandon` | Stop holding the lease without retrying. Route to a native dead-letter facility if available; otherwise equivalent to `ack`. |
| `reapExpired` | Reclaim messages whose lease expired (crashed/stalled worker) so they become eligible again. Return the count recovered. Backends with native lease recovery (Beanstalk, Service Bus) may make this a no-op. |

The `JobLease` you return carries the decoded `JobEnvelope` (whose `payload` is the wire `stdClass`),
an opaque `token` you need to ack/nack later, the `ownerToken`, an `expiresAt` deadline, and the
backend name. Use `JobLease::withRelativeExpiry($envelope, $token, $owner, $seconds, $backend)` to
compute the deadline as "now + N seconds".

### Registering the backend

Add your class to `Config\Jobs::$backends` under a key, then select it by name:

```php
// app/Config/Jobs.php
public array $backends = [
    'sync'       => \Daycry\Jobs\Queues\Backends\SyncBackend::class,
    'database'   => \Daycry\Jobs\Queues\Backends\DatabaseBackend::class,
    'redis'      => \Daycry\Jobs\Queues\Backends\RedisBackend::class,
    'beanstalk'  => \Daycry\Jobs\Queues\Backends\BeanstalkBackend::class,
    'serviceBus' => \Daycry\Jobs\Queues\Backends\ServiceBusBackend::class,
    'sqs'        => \App\Queues\SqsBackend::class, // your backend
];
```

```php
Jobs::define('command', 'app:report')->queue('reports')->dispatch('sqs');
$backend = Jobs::backend('sqs');
```

> **Warning:** Because delivery is at-least-once, design `reapExpired()` and your visibility timeout
> carefully: the timeout **must** exceed the maximum expected job runtime, or the reaper will reclaim
> a message that is still being processed and cause a duplicate run. See
> [Queues & Backends](QUEUES.md) for the existing backends' visibility-timeout settings.

### Advanced custom handlers

Beyond the [basic handler](#custom-handlers) and [typed handlers](#typed-handlers), two patterns are
worth calling out:

- **Stateful setup/teardown** — use `beforeRun()`/`afterRun()` for resource lifecycle (open/commit a
  DB transaction, acquire/release an external lease). `afterRun()` always runs and receives the
  `ExecutionResult`, so it is the right place for cleanup that must happen on both success and
  failure. Remember its exceptions are swallowed.
- **Output normalisation** — return arrays/objects to have them JSON-encoded into the recorded
  output, or write to `stdout`; the runtime appends captured output to a string return value. Avoid
  returning resources or non-serialisable values.

Handlers are constructed with **no constructor arguments**, so resolve dependencies through
CodeIgniter services inside the handler. See [Handlers](handlers.md) for the full contract.

## Metrics

Provide a custom collector implementing `Daycry\Jobs\Metrics\MetricsCollectorInterface` and set
`Config\Jobs::$metricsCollector`. The default `InMemoryMetricsCollector` is fine for local/dev but not
for production scraping (set it to `null` to disable collection entirely). The worker emits counters
such as `jobs_fetched`, `jobs_succeeded`, `jobs_failed`, `jobs_requeued`, `jobs_failed_permanently`,
`jobs_skipped_idempotent` and `jobs_rejected_signature`, each tagged with the `queue`.
