# Handlers: Running Work

A **handler** is where your business logic lives. In v3 a handler is intentionally small: it contains
*only* the logic to process a payload. Unlike the v1 design it does **not** extend any builder and
never carries scheduling, queue or retry state. It receives an immutable `JobContext` and returns a
value that the runtime normalises into recorded output. Throwing any `Throwable` signals failure.

This page covers the handler contract, the `JobContext` it receives, the five built-in handlers (with
their payload shapes and security defaults), how to write and register your own handler, and the
typed-DTO base class `TypedJobHandler`.

- [The contract: `JobHandlerInterface`](#the-contract-jobhandlerinterface)
- [`AbstractJobHandler`](#abstractjobhandler)
- [`JobContext`](#jobcontext)
- [Built-in handlers](#built-in-handlers)
- [Writing your own handler](#writing-your-own-handler)
- [Typed handlers (`TypedJobHandler`)](#typed-handlers-typedjobhandler)
- [Handler resolution and per-queue allowlist](#handler-resolution-and-per-queue-allowlist)

---

## The contract: `JobHandlerInterface`

`Daycry\Jobs\Handlers\JobHandlerInterface` defines three methods:

```php
namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Execution\JobContext;

interface JobHandlerInterface
{
    /** Main business logic; return value is normalised upstream. Throwing signals failure. */
    public function handle(JobContext $ctx): mixed;

    /** Optional hook executed before handle(). */
    public function beforeRun(JobContext $ctx): void;

    /** Optional hook executed after handle(), regardless of logical success. */
    public function afterRun(JobContext $ctx, ExecutionResult $result): void;
}
```

| Method | When it runs | Notes |
|--------|--------------|-------|
| `beforeRun($ctx)` | Before `handle()` | Setup hook. If it throws, the attempt fails before `handle()` runs. |
| `handle($ctx)` | The main work | Return any value (normalised to string/JSON output). Throw any `Throwable` to fail. |
| `afterRun($ctx, $result)` | After `handle()`, always | Receives the `ExecutionResult`. Best-effort: any exception it throws is **swallowed** and never changes the recorded outcome. |

> **Note:** The return value of `handle()` is normalised by the runtime: `null` stays `null`, scalars
> become strings, and arrays/objects are JSON-encoded. Captured `stdout` output is appended to a
> string return. See [Architecture](ARCHITECTURE.md) for the full `JobRuntime` normalisation flow.

---

## `AbstractJobHandler`

Most handlers only need `handle()`. Extend `Daycry\Jobs\Handlers\AbstractJobHandler`, which provides
no-op implementations of `beforeRun()` and `afterRun()`:

```php
abstract class AbstractJobHandler implements JobHandlerInterface
{
    public function beforeRun(JobContext $ctx): void {}
    public function afterRun(JobContext $ctx, ExecutionResult $result): void {}
}
```

Override the hooks only when you need them.

---

## `JobContext`

`Daycry\Jobs\Execution\JobContext` is the **immutable** object handed to every handler. It decouples
handlers from the definition/builder — a handler only ever sees the payload to process plus read-only
metadata about the current run.

| Field | Type | Description |
|-------|------|-------------|
| `payload` | `mixed` | The payload you dispatched (string, array, object, callable, …). |
| `name` | `?string` | Logical job name (for logs/metrics); `null` if none was set. |
| `queue` | `?string` | The queue the message came from. |
| `attempt` | `int` | 1-based attempt counter (`1` on the first run, `2` on the first retry, …). |
| `meta` | `array<string, mixed>` | Free-form metadata propagated from the envelope. |

```php
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\AbstractJobHandler;

final class ResizeImageHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $path = $ctx->payload['path'] ?? throw new \InvalidArgumentException('path required');

        if ($ctx->attempt > 1) {
            log_message('info', "Retrying resize of {$path} (attempt {$ctx->attempt}).");
        }

        // ... resize ...

        return ['resized' => $path];
    }
}
```

There is also a convenience factory `JobContext::fromPayload(mixed $payload)` that builds a context
with only the payload set (used when running a handler outside the queue pipeline).

---

## Built-in handlers

Five handler keys are registered by default in `Config\Jobs::$handlers`:

| Key | Class | Payload shape | Security posture |
|-----|-------|---------------|------------------|
| `command` | `CommandHandler` | non-empty `string` (a spark command) | Trusted input — gate untrusted queues with `$queueHandlers`. |
| `shell` | `ShellHandler` | `string` or `list<string>` argv | Deny-by-default allowlist; `proc_open` argv (no shell). |
| `closure` | `ClosureHandler` | any `callable` | `sync` backend only (closures don't serialise). |
| `event` | `EventHandler` | `['name' => string, 'data' => array?]` | Event-name allowlist; empty = deny all. |
| `url` | `UrlHandler` | `['method' => string, 'url' => string, 'options' => array?]` | SSRF-hardened; http/https only, no redirects. |

### `command` — run a spark command

Executes a CodeIgniter spark command via the framework `command()` helper. The payload must be a
non-empty string.

```php
Jobs::define('command', 'app:report --month=2026-06')
    ->queue('reports')
    ->dispatch();
```

> **Warning:** The command string is **trusted** — it is executed as-is. Never let an untrusted
> source enqueue to a queue that allows `command`. Restrict it with the per-queue allowlist
> (see [below](#handler-resolution-and-per-queue-allowlist)).

### `shell` — run an OS command

Executes an OS command via `proc_open` using an **argv array**, never through `/bin/sh -c`. This
removes the shell-metacharacter attack surface entirely. The return value preserves the v1-style
array-of-output-lines shape (non-empty lines of stdout).

Payload may be a `string` (split on whitespace) or — preferably — a `list<string>` argv array so
arguments containing spaces survive intact:

```php
// argv array (recommended): each element is a discrete argument.
Jobs::define('shell', ['/usr/bin/rsync', '-a', '/src/with spaces/', '/dst/'])
    ->queue('ops')
    ->dispatch();

// string form (split on whitespace) — avoid for args with spaces.
Jobs::define('shell', '/bin/ls -la /var/log')->queue('ops')->dispatch();
```

**Deny-by-default authorization.** With an empty `Config\Jobs::$allowedShellCommands` and
`$allowAllShellCommands = false`, execution is **refused**. To permit commands, list their absolute
paths (the handler compares `realpath()` of the candidate against `realpath()` of each allowed entry):

```php
// app/Config/Jobs.php
public array $allowedShellCommands = [
    '/usr/bin/rsync',
    '/bin/ls',
];

// Insecure escape hatch — allow ANY binary (NOT recommended):
public bool $allowAllShellCommands = false;
```

> **Warning:** Setting `$allowAllShellCommands = true` disables the allowlist entirely. Only do this
> on fully trusted, private backends. Prefer an explicit absolute-path allowlist in production.

### `closure` — run a callable

Executes a user-provided callable carried in the payload (a `Closure` or any object with
`__invoke`).

```php
Jobs::define('closure', static fn (): string => 'done')
    ->dispatch(); // sync backend only
```

> **Warning:** Closures cannot be JSON-encoded, so `closure` only works in-process on the **`sync`**
> backend (and for inline cron jobs). There is **no dedicated upstream validation**: dispatching a
> closure to a persistent backend fails at enqueue time, where `EnvelopeFactory::toWire()` signs the
> envelope and `json_encode(..., JSON_THROW_ON_ERROR)` raises a generic `JsonException` (not a
> closure-specific message). `ClosureHandler`'s own `is_callable` guard only fires on the in-process
> `sync` path; a deserialised remote payload would no longer be callable anyway. To model a workflow
> across backends, dispatch the next job explicitly from inside a regular handler — see
> [Advanced topics](advanced.md#callbacks-and-middleware-the-v3-model).

### `event` — trigger a CodeIgniter event

Triggers a CodeIgniter event named in the payload. **Secure-by-default**: only events listed in
`Config\Jobs::$allowedEvents` may fire; an empty allowlist denies everything.

Payload: `['name' => string, 'data' => array(optional)]`.

```php
// app/Config/Jobs.php
public array $allowedEvents = ['user.registered', 'order.completed'];
```

```php
Jobs::define('event', ['name' => 'user.registered', 'data' => ['user_id' => 123]])
    ->queue('events')
    ->dispatch();
```

The handler passes `data` to `Events::trigger()` (wrapping a non-array `data` in a single-element
array). A tampered message naming an event outside the allowlist is rejected with a `JobException`.

### `url` — perform an HTTP request

Performs an HTTP request via the CodeIgniter `curlrequest` service. It is **SSRF-hardened**:

- Scheme allowlist: `http` / `https` only.
- Method allowlist: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`.
- Private/reserved IPv4 **and** IPv6 targets are rejected (resolving A/AAAA records; if *any*
  resolved address is internal the request is refused).
- SSL verification is forced on (`verify` / `CURLOPT_SSL_VERIFYPEER` / `CURLOPT_SSL_VERIFYHOST` are
  stripped from `options` so they can't be disabled).
- Redirects are disabled (`allow_redirects = false`) so a 3xx cannot bounce to an internal host.

Payload: `['method' => string, 'url' => string, 'options' => array(optional)]`. The body of the
response is returned.

```php
Jobs::define('url', [
    'method'  => 'POST',
    'url'     => 'https://api.example.com/webhooks/notify',
    'options' => ['json' => ['event' => 'job.done']],
])->queue('web')->dispatch();
```

> **Warning:** DNS rebinding (a different IP at cURL time than at validation time) is **not** fully
> mitigated. Handlers that need that guarantee should pin DNS resolution. All other internal targets
> (localhost, RFC 1918, link-local, reserved ranges) are blocked.

---

## Writing your own handler

Implement `JobHandlerInterface` (or extend `AbstractJobHandler`) and register the class under a key in
`Config\Jobs::$handlers`. Then dispatch by that key.

### 1. Write the handler

```php
<?php

namespace App\Jobs;

use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\AbstractJobHandler;

final class SendInvoiceHandler extends AbstractJobHandler
{
    public function beforeRun(JobContext $ctx): void
    {
        // optional: open a transaction, warm a cache, etc.
    }

    public function handle(JobContext $ctx): mixed
    {
        $invoiceId = $ctx->payload['invoice_id'] ?? null;
        if ($invoiceId === null) {
            // Throwing any Throwable marks the attempt as failed.
            throw new \InvalidArgumentException('invoice_id is required');
        }

        // ... business logic: render PDF, email it, mark sent ...

        // Returned value is recorded as the job's output (arrays are JSON-encoded).
        return ['sent' => true, 'invoice_id' => $invoiceId];
    }

    public function afterRun(JobContext $ctx, \Daycry\Jobs\Execution\ExecutionResult $result): void
    {
        // optional: always-run teardown. Exceptions here are swallowed.
        log_message('info', "SendInvoice finished: success={$result->success}");
    }
}
```

### 2. Register the key

```php
// app/Config/Jobs.php
public array $handlers = [
    // built-in keys
    'command' => \Daycry\Jobs\Handlers\CommandHandler::class,
    'shell'   => \Daycry\Jobs\Handlers\ShellHandler::class,
    'closure' => \Daycry\Jobs\Handlers\ClosureHandler::class,
    'event'   => \Daycry\Jobs\Handlers\EventHandler::class,
    'url'     => \Daycry\Jobs\Handlers\UrlHandler::class,

    // your handler
    'send-invoice' => \App\Jobs\SendInvoiceHandler::class,
];
```

### 3. Dispatch by key

```php
use Daycry\Jobs\Jobs;

Jobs::define('send-invoice', ['invoice_id' => 42])
    ->queue('billing')
    ->maxRetries(3)
    ->dispatch();
```

> **Note:** The registry validates that the configured class implements `JobHandlerInterface`; a
> missing or invalid key throws a `JobException`. Handlers are instantiated with **no constructor
> arguments** (`new $class()`), so resolve any dependencies via CodeIgniter services inside the
> handler rather than through constructor injection.

---

## Typed handlers (`TypedJobHandler`)

`Daycry\Jobs\Handlers\TypedJobHandler` is a base class for handlers that want a **typed DTO** instead
of the loosely-typed `mixed` payload. You declare the DTO class via `payloadType()` and implement
`run(object $payload)`; the base class rehydrates the payload before calling `run()`.

```php
abstract class TypedJobHandler extends AbstractJobHandler
{
    abstract public function payloadType(): string;   // class-string of the DTO
    abstract protected function run(object $payload): mixed; // your logic
}
```

### Example: DTO + typed handler

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

### Hydration rules

`TypedJobHandler::handle()` rehydrates `$ctx->payload` into an instance of `payloadType()`:

1. If the payload is **already** an instance of the expected type, it is used directly.
2. Otherwise the payload is normalised to an associative array:
   - `array` is used as-is;
   - `object` is converted via `get_object_vars()`;
   - `string` is `json_decode`d (must decode to an array).
3. The DTO is constructed via its constructor, matching parameters **by name**. Missing values fall
   back to a parameter's default, then to `null` if the parameter is nullable. A required parameter
   with no value and no default raises a validation error.
4. As a best-effort step, any remaining keys that match **public, non-readonly** properties are set
   directly (typed-property mismatches are silently ignored).

```php
// Any of these payloads hydrate an ImportRequest:
Jobs::define('process-import', ['source' => 's3://bucket/file.csv', 'batchSize' => 250])->dispatch();
Jobs::define('process-import', '{"source":"s3://bucket/file.csv"}')->dispatch(); // batchSize -> default 100
Jobs::define('process-import', new ImportRequest('local.csv'))->dispatch();      // re-hydrated remotely
```

> **Warning:** A non-array/object/JSON-string payload (e.g. a bare integer) cannot be hydrated and
> raises a `JobException::validationError`. Likewise, `payloadType()` must return an existing class.
> For payloads that travel through a remote queue, prefer arrays or JSON strings. A **plain** DTO
> object still works on a persistent backend — it is JSON-encoded at enqueue and re-hydrated from the
> wire (via `get_object_vars()`) on the consumer — so it does *not* arrive as the same instance, only
> as one reconstructed by `payloadType()`. A payload that cannot be JSON-encoded (e.g. one holding a
> closure or a resource) fails at enqueue time with a `JsonException`; such payloads only work on the
> `sync` backend, which never serialises.

---

## Handler resolution and per-queue allowlist

At consume time, `Daycry\Jobs\Handlers\HandlerRegistry` resolves a handler key to an instance using
`Config\Jobs::$handlers`, and enforces the **per-queue allowlist** `Config\Jobs::$queueHandlers`.

- `resolve(string $key)` — map the key to its class and instantiate it (throws on an unknown/invalid
  key).
- `resolveForQueue(string $key, string $queue)` — additionally enforce that `$key` is allowed on
  `$queue`. A queue **absent** from `$queueHandlers` (or with no entry) imposes **no** restriction.

```php
// app/Config/Jobs.php — restrict which handlers each queue may run.
public array $queueHandlers = [
    'reports' => ['command'],       // only 'command' on the reports queue
    'web'     => ['url', 'event'],  // only 'url' and 'event' on the web queue
];
```

If a message on the `web` queue tries to run `shell`, the runtime throws and the attempt fails.

> **Warning:** Leaving `$queueHandlers` empty means **every** queue can run **every** handler,
> including `shell` and `command`. In production, define an explicit allowlist per queue so untrusted
> or remote queues cannot reach dangerous handlers. This is the single most important hardening step
> for multi-tenant or internet-facing queues.

---

## See also

- [Jobs](jobs.md) — defining and dispatching the work a handler runs.
- [Advanced topics](advanced.md) — the v3 callback model, idempotency, signing and custom backends.
- [Configuration](CONFIGURATION.md) — `$handlers`, `$queueHandlers`, `$allowedEvents`,
  `$allowedShellCommands` and the rest of `Config\Jobs`.
- [Queues & Backends](QUEUES.md) — how messages reach a handler.
- [Architecture](ARCHITECTURE.md) — the runtime that drives a handler through one attempt.
