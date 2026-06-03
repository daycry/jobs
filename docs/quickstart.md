# Quick Start

This is an end-to-end tutorial. By the end you will have written a custom handler, dispatched a job
to a real (out-of-process) queue, processed it with a worker, scheduled a recurring job from the
operating system cron, and turned on envelope signing. Each step shows complete code plus the output
you should expect.

This guide assumes you have completed [Installation](installation.md) (composer require, migrations,
and — for steps 2 onward — a `database` backend).

---

## Prerequisites

In `app/Config/Jobs.php`, use the `database` backend so dispatch enqueues for a separate worker
instead of running inline:

```php
namespace Config;

class Jobs extends \Daycry\Jobs\Config\Jobs
{
    public string $worker        = 'database';
    public array|string $queues  = 'default,reports';
}
```

Make sure the migrations have run:

```bash
php spark migrate -n "Daycry\Jobs"
```

---

## Step 1 — Define a job

You can dispatch work without writing any code at all by using a built-in handler. The `command`
handler runs a CodeIgniter spark command; its payload is the command string.

```php
use Daycry\Jobs\Jobs;

$id = Jobs::define('command', 'app:report --month=06')
    ->named('monthly-report')
    ->queue('reports')
    ->maxRetries(3)
    ->dispatch();

echo $id; // e.g. "42" (the database row id) — the backend-assigned id
```

### Writing a custom handler

For your own business logic, implement a handler. The simplest path is to extend
`AbstractJobHandler` (which provides no-op `beforeRun()` / `afterRun()` hooks) and implement
`handle()`. The handler receives a read-only [`JobContext`](handlers.md) and returns any value; the
runtime normalises scalars/arrays to a string or JSON, and **throwing any exception signals
failure**.

```php
// app/Jobs/SendWelcomeEmail.php
namespace App\Jobs;

use Daycry\Jobs\Handlers\AbstractJobHandler;
use Daycry\Jobs\Execution\JobContext;

final class SendWelcomeEmail extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;            // whatever you dispatched
        $userId  = $payload['user_id'] ?? null;

        if ($userId === null) {
            // Throwing marks the attempt failed; the worker will retry / dead-letter.
            throw new \RuntimeException('user_id missing from payload');
        }

        // ... send the email ...

        return "welcome email sent to user {$userId}";
    }
}
```

Register the handler key in `Config\Jobs::$handlers`:

```php
public array $handlers = [
    'command' => \Daycry\Jobs\Handlers\CommandHandler::class,
    'shell'   => \Daycry\Jobs\Handlers\ShellHandler::class,
    'closure' => \Daycry\Jobs\Handlers\ClosureHandler::class,
    'event'   => \Daycry\Jobs\Handlers\EventHandler::class,
    'url'     => \Daycry\Jobs\Handlers\UrlHandler::class,
    'welcome' => \App\Jobs\SendWelcomeEmail::class, // your handler
];
```

> **Note:** A handler contains business logic **only**. It never extends the builder and never
> carries scheduling or queue state — that lives in the `JobDefinition`. See [Handlers](handlers.md)
> for `TypedJobHandler` (typed DTO payloads) and the lifecycle hooks.

---

## Step 2 — Dispatch to a queue

Dispatch your custom job. Because `$worker = 'database'`, the definition is persisted to the
`queues` table and `dispatch()` returns the row id — nothing runs yet.

```php
use Daycry\Jobs\Jobs;

$id = Jobs::define('welcome', ['user_id' => 123])
    ->named('welcome-123')
    ->queue('default')
    ->maxRetries(2)
    ->dispatch();

echo "queued as {$id}\n";
```

```
queued as 7
```

You can target a specific backend per dispatch (overriding the default `$worker`):

```php
$id = Jobs::define('welcome', ['user_id' => 123])->dispatch('redis');
```

> **Note:** With the default `sync` backend, `dispatch()` runs the job inline immediately and returns
> a synthetic `sync-xxxxxxxxxxxx` id; there is nothing to fetch later. The rest of this tutorial
> assumes a persistent backend such as `database`.

---

## Step 3 — Run a worker

Start a worker for the `default` queue:

```bash
php spark jobs:queue:work default
```

Expected output for one successful job:

```
[Worker] processing queue 'default'
[acked] default (attempt 1)
```

The worker leases one ready message, verifies its signature, runs **one** attempt, then `ack`s on
success. It keeps polling (sleeping `pollInterval` seconds when the queue is empty) until you stop
it with `Ctrl+C`:

```
[Worker] stop signal received, finishing current cycle...
[Worker] graceful shutdown complete.
```

### Bounded runs

For cron-driven or test runs, bound the number of cycles:

```bash
# Process exactly one cycle and exit (useful from system cron):
php spark jobs:queue:work default --once

# Process at most 100 cycles then exit:
php spark jobs:queue:work default --max 100

# Use a different backend for this run only:
php spark jobs:queue:work default --backend redis
```

### What the statuses mean

| Worker status | Meaning |
|---------------|---------|
| `acked` | Attempt succeeded; message removed. |
| `requeued` | Attempt failed but retries remain; `nack`ed with backoff delay. |
| `dead-lettered` | Retries exhausted; `backend->abandon()` is called. `database`/`redis` drop the message from the active queue; `beanstalk` buries it and Service Bus dead-letters it natively. The app-level `DeadLetterQueue::store()` helper is **opt-in** and is **not** invoked automatically. |
| `skipped-idempotent` | The idempotency key was already processed; `ack`ed without running. |
| `rejected` | Invalid payload or **invalid signature**; `abandon`ed. |
| `empty` | No ready message this cycle. |

> **Warning:** Delivery is **at-least-once**. A worker may run the same message more than once (for
> example after a crash before `ack`). Make handlers idempotent, and/or set an
> [`idempotencyKey()`](advanced.md#idempotency-in-depth). See [Architecture](ARCHITECTURE.md#at-least-once-delivery).

### Recover crashed workers

If a worker dies mid-job, its lease eventually expires. Reclaim stranded messages with the reaper
(for `database` and `redis`; Beanstalk and Service Bus recover natively):

```bash
php spark jobs:queue:reap default
```

```
Reaped 1 expired message(s) from queue 'default'.
```

Run it periodically from cron (see [Operations](operations.md)).

---

## Step 4 — Schedule a recurring job

Register schedules in `Config\Jobs::init()`. Each `define()` returns a `JobBuilder`, so you chain
frequency, identity and queue helpers exactly like with `Jobs::define()`:

```php
namespace Config;

use Daycry\Jobs\Cron\Scheduler;

class Jobs extends \Daycry\Jobs\Config\Jobs
{
    public function init(Scheduler $scheduler): void
    {
        // Enqueued onto the 'reports' queue (it declares a queue):
        $scheduler->define('command', 'app:report')
            ->named('daily-report')
            ->dailyAt('02:00')
            ->queue('reports');

        // Runs inline every minute (no queue), single-instance to avoid overlap:
        $scheduler->define('shell', ['ls', '-la'])
            ->named('list-dir')
            ->everyMinute()
            ->singleInstance();

        // Production-only, hourly:
        $scheduler->define('welcome', ['user_id' => 1])
            ->named('hourly-welcome')
            ->hourly()
            ->environments('production');
    }
}
```

Drive the runner once per minute from your operating system's crontab:

```cron
* * * * * cd /var/www/app && php spark jobs:cronjob:run >> /dev/null 2>&1
```

On every minute, `jobs:cronjob:run`:

1. builds a `Scheduler` and calls your `init()`;
2. walks the definitions in **topological order** of `dependsOn()`;
3. skips disabled jobs and jobs outside the current `environments()`;
4. for each **due** definition, **enqueues** it when it declares a `queue`, otherwise runs it
   **inline** (one attempt).

You can test the schedule deterministically without waiting for the clock:

```bash
php spark jobs:cronjob:run -testTime "2026-06-03 02:00:00"
```

> **Note:** Cron only decides *when*. Jobs with a `queue` are still processed by a running
> `jobs:queue:work` worker — make sure one is running for the queues you schedule onto. See
> [Scheduling](scheduling.md).

---

## Step 5 — Sign envelopes

Signing is on by default (`Config\Jobs::$verifyEnvelopeSignature = true`); it only takes effect once
a key is available. Set one in `.env`:

```dotenv
JOBS_SIGNING_KEY = "a-long-random-secret-shared-by-producers-and-workers"
```

At enqueue time, `EnvelopeFactory` computes an HMAC-SHA256 (`_sig`) over the **immutable identity
fields** of the envelope (`job`, `payload`, `queue`, `priority`, `maxRetries`, `name`,
`identifier`). The mutable `attempts` and `schedule` fields are excluded so the signature survives a
requeue.

When the worker fetches a message it recomputes the HMAC and compares with `hash_equals()`
(constant-time). A tampered or forged message is rejected:

```
[rejected] default (attempt 0) - invalid signature
```

and logged as `critical`.

> **Warning:** The signing key must be **identical** on every process that enqueues and every worker
> that consumes. If you rotate the key, drain the queue first or messages signed with the old key
> will be rejected. For trusted, private backends you can set `$verifyEnvelopeSignature = false`, but
> this is discouraged. See [Security](security.md).

---

## Where to go next

- [Jobs & Builder](jobs.md) — every builder method and frequency helper.
- [Handlers](handlers.md) — typed payloads, lifecycle hooks, the built-in handlers.
- [Queues & Workers](QUEUES.md) — choosing and tuning a backend.
- [Retries](RETRIES.md) — backoff strategies and the dead-letter queue.
- [Concurrency](concurrency.md) — idempotency and single-instance locks.
- [Operations](operations.md) — running this in production.
