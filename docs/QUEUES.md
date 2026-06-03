# Queues & Backends

The queue system is the asynchronous heart of `daycry/jobs` v3. You describe a unit of work with
the fluent builder, **dispatch** it onto a backend, and a **worker** process later pulls it off the
queue and runs it. Every backend implements one small, lease-oriented contract —
`Daycry\Jobs\Queues\QueueBackend` — so the worker pipeline, the cron runner and your application
code are completely decoupled from where messages are actually stored (a SQL table, Redis,
beanstalkd or Azure Service Bus).

This page documents how to enqueue work, the `QueueBackend` contract and `JobLease` lifecycle, the
worker pipeline, the **at-least-once** delivery guarantee, and the precise semantics and
configuration of each shipped backend.

> **Note:** Delivery is at-least-once for every persistent backend. A message may be delivered more
> than once (after a crash, a lease expiry, or a reaper recovery), so your handlers should be
> idempotent. See [Idempotency](advanced.md#idempotency-in-depth).

## Enqueueing work

There are two entry points, both on the `Daycry\Jobs\Jobs` facade.

### `Jobs::define(...)->dispatch()`

`Jobs::define($handler, $payload)` opens a fluent `JobBuilder`. Chain configuration, then call
`dispatch()` to serialize and enqueue the resulting immutable `JobDefinition`. With no argument
`dispatch()` uses the default backend (`Config\Jobs::$worker`); pass a backend name to override it.
The return value is the backend-assigned identifier (a string).

```php
use Daycry\Jobs\Jobs;

// Enqueue onto the default backend ($worker)
$id = Jobs::define('command', 'app:report')
    ->queue('reports')
    ->maxRetries(3)
    ->dispatch();

// Enqueue onto a specific backend by name
$id = Jobs::define('command', 'app:report')
    ->queue('reports')
    ->dispatch('database');

// Schedule for the future (honoured by database/redis/beanstalk/serviceBus)
$id = Jobs::define('url', ['method' => 'POST', 'url' => 'https://example.com/webhook'])
    ->queue('web')
    ->scheduledAt(new DateTimeImmutable('+10 minutes'))
    ->dispatch('redis');
```

### `Jobs::backend()`

When you want to talk to the backend directly (for example to enqueue a pre-built definition, or to
call lease verbs in a test), resolve it with `Jobs::backend()`:

```php
use Daycry\Jobs\Jobs;

$backend = Jobs::backend('redis');   // or Jobs::backend() for the default $worker
$id      = $backend->enqueue(
    Jobs::define('command', 'app:report')->queue('reports')->toDefinition(),
);
```

Backends are resolved through `Daycry\Jobs\Queues\BackendFactory`, which maps a name to a class via
`Config\Jobs::$backends` and throws `QueueException::forInvalidWorker()` for an unknown name.

> **Note:** With the default `sync` backend, `dispatch()`/`enqueue()` runs the job **inline** and
> returns a synthetic id. Nothing is stored and no worker is involved. Switch `$worker` (or pass a
> backend name) to a persistent backend for real asynchronous processing.

## The `QueueBackend` contract

Every backend implements this interface. The contract is stateless with respect to the in-flight
message: the worker holds a `JobLease` and passes it back to `ack`/`nack`/`abandon`, so the backend
never has to remember which message a given worker is currently processing.

```php
namespace Daycry\Jobs\Queues;

use Daycry\Jobs\Definition\JobDefinition;

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

| Method | Responsibility |
|--------|----------------|
| `enqueue(JobDefinition): string` | Persist the definition into the queue. Returns the backend-assigned id. |
| `fetch(string $queue): ?JobLease` | Lease one ready message, or `null` when the queue is empty. The lease carries a fresh owner token and a visibility deadline. |
| `ack(JobLease): bool` | Mark the leased message as successfully processed; the backend removes it permanently. |
| `nack(JobLease, ?int $delaySeconds): bool` | Processing failed — redeliver the message, optionally after `$delaySeconds` (used to apply retry backoff). Persistent backends requeue in place. |
| `abandon(JobLease): bool` | Stop holding the lease without retrying (unprocessable / retries exhausted). Routes to a native dead-letter facility where available; otherwise equivalent to `ack()`. |
| `reapExpired(string $queue, int $visibilityTimeout): int` | Reclaim messages whose lease expired (crashed/stalled worker) so they become eligible again. Returns the number of messages recovered. |

## The `JobLease`

`fetch()` returns a `Daycry\Jobs\Queues\JobLease` (an immutable value object) for each in-flight
message. The same lease is handed back to settle the message.

```php
final readonly class JobLease
{
    public JobEnvelope $envelope;       // the decoded message
    public string $token;               // opaque backend identifier needed to settle
    public string $ownerToken;          // random token minted by the worker at fetch()
    public DateTimeImmutable $expiresAt;// visibility deadline
    public string $backend;             // backend name
}
```

| Field | Meaning |
|-------|---------|
| `envelope` | The decoded `JobEnvelope` (payload, name, queue, attempts, priority, meta, raw). |
| `token` | Opaque backend handle used to ack/nack/abandon: the row primary key (database), the raw payload string (redis processing list), the `messageId`/`LockToken` location (Service Bus), or the job id (beanstalk). |
| `ownerToken` | A random token minted by the worker at `fetch()` and stored alongside the in-flight record. The reaper only reclaims **expired** leases, and ownership is verified so a reaped-then-reassigned message cannot be settled by the previous owner. |
| `expiresAt` | The visibility deadline. Past this time the backend may redeliver the message to another consumer. |
| `backend` | The backend name (`database`, `redis`, `beanstalk`, `servicebus`, `sync`). |

Helpers:

- `JobLease::withRelativeExpiry($envelope, $token, $owner, $secondsFromNow, $backend)` — factory that
  computes the deadline as *now + N seconds* (used by every backend's `fetch()`).
- `$lease->isExpired()` — whether the visibility deadline has passed. A worker that detects this must
  **not** ack, because the message may already have been redelivered.
- `$lease->renew($secondsFromNow)` — returns a copy with an extended deadline (token/owner
  preserved). The redis backend re-stamps its in-flight record on `renewLease()`.

## The wire envelope

Every backend serializes the same canonical JSON object (built by
`Daycry\Jobs\Queues\EnvelopeFactory::toWire()`) so a message enqueued through one path can be
consumed through another:

```json
{
  "job":            "command",
  "payload":        "app:report",
  "queue":          "reports",
  "priority":       5,
  "maxRetries":     3,
  "attempts":       0,
  "name":           "daily-report",
  "identifier":     "9f1c2a...",
  "idempotencyKey": null,
  "schedule":       null,
  "_sig":           "<HMAC-SHA256>"
}
```

The `_sig` HMAC is computed over the **immutable identity fields only** (`job`, `payload`, `queue`,
`priority`, `maxRetries`, `name`, `identifier`, `idempotencyKey`) — the mutable `attempts` and `schedule` are excluded
so the signature survives a requeue (where backends re-serialize with `attempts + 1`). See
[Envelope signing](advanced.md#envelope-signing).

## The worker pipeline

The long-running worker (`Daycry\Jobs\Worker\QueueWorker`, driven by the `jobs:queue:work` command)
runs every message through a fixed pipeline. Each call to `processOnce($queue)` performs exactly one
cycle:

```text
fetch  ->  verify signature  ->  idempotency guard  ->  run ONE attempt  ->  ack / nack(backoff) / abandon
```

1. **fetch** — lease one ready message. If the queue is empty, returns a `WorkerResult('empty')`.
2. **verify signature** — when `Config\Jobs::$verifyEnvelopeSignature` is true and a signing key is
   configured, the `_sig` HMAC is verified. A missing or invalid signature is `abandon`ed and logged
   as `critical` (`WorkerResult('rejected')`).
3. **idempotency guard** — if the envelope carries an `idempotencyKey` that was already processed,
   the message is acked and skipped (`WorkerResult('skipped-idempotent')`).
4. **run one attempt** — the job runs exactly once through `JobRuntime` (handler resolution, timeout,
   single-instance lock, output capture). The runtime never loops or sleeps for retries.
5. **settle**:
   - **success** → `ack` (message removed) → `WorkerResult('acked')`.
   - **failure with retries left** (`attemptIndex < maxRetries`) → `nack($lease, $delay)` where
     `$delay` comes from the backoff policy → `WorkerResult('requeued')`.
   - **failure with retries exhausted** → `abandon($lease)` (dead-letter / drop) → logged `critical`
     → `WorkerResult('dead-lettered')`.

> **Note:** The retry decision lives in the **worker**, not the runtime. A job runs at most
> `maxRetries + 1` times in total. See [Retries & Backoff](RETRIES.md).

### `WorkerResult` statuses

`QueueWorker::processOnce()` returns a `Daycry\Jobs\Worker\WorkerResult` whose `status` is one of:

| Status | Meaning |
|--------|---------|
| `empty` | The queue had no ready message. |
| `acked` | The job succeeded and was removed. |
| `requeued` | The job failed but had retries left; it was nacked with a backoff delay. |
| `dead-lettered` | The job failed and exhausted its retries; it was abandoned. |
| `rejected` | The message had an invalid payload or signature; it was abandoned. |
| `skipped-idempotent` | The idempotency key had already been processed; the message was acked without running. |

## At-least-once delivery

Persistent backends guarantee that a successfully enqueued message is delivered **at least once**.
The worker holds the message under a visibility lease while it runs; the message is only removed on
`ack`. If the worker crashes between `fetch()` and `ack()`, the lease eventually expires and the
message becomes eligible again (via the [reaper](#recovering-crashed-workers-reaper) for
database/redis, or natively for beanstalk/Service Bus). This means:

- A job can run more than once. **Make handlers idempotent** (use `idempotencyKey()` for built-in
  de-duplication — see [Idempotency](advanced.md#idempotency-in-depth)).
- A `nack` re-serializes the message with `attempts + 1` so the retry counter is never lost on
  redelivery.

## Recovering crashed workers (reaper)

If a worker dies while holding a lease, the message is invisible until its visibility timeout
elapses. The `jobs:queue:reap` command calls `QueueBackend::reapExpired()` to return such messages
to the ready state:

```bash
# Recover stranded messages on the 'reports' queue (default backend)
php spark jobs:queue:reap reports

# Explicit backend
php spark jobs:queue:reap reports --backend redis
```

Run it periodically (for example once a minute from system cron) for the **database** and **redis**
backends. **beanstalk** and **serviceBus** recover in-flight messages natively when their
reservation/lock expires, so their `reapExpired()` is a no-op (returns `0`).

The visibility timeout applied by the command is `redisProcessingVisibilityTimeout` when the backend
is `redis`, and `databaseVisibilityTimeout` otherwise (both default to `300` seconds).

> **Warning:** Set the visibility timeout **greater than your longest expected job runtime**. If a
> job runs longer than the timeout, the reaper (or the broker) will consider the worker crashed and
> redeliver the message while it is still running — causing a duplicate execution.

---

## Backends

Backends are declared in `Config\Jobs::$backends` (name → class) and the default is selected by
`Config\Jobs::$worker`:

```php
public array $backends = [
    'sync'       => SyncBackend::class,
    'database'   => DatabaseBackend::class,
    'redis'      => RedisBackend::class,
    'beanstalk'  => BeanstalkBackend::class,
    'serviceBus' => ServiceBusBackend::class,
];

public string $worker = 'sync'; // default backend when none is specified
```

Available queue names come from `Config\Jobs::$queues` (a comma-separated string or a list). The
worker defaults to the **first** configured queue when you do not name one.

### sync

Synchronous, in-process. `SyncBackend::enqueue()` does **not** store anything — it runs the job
inline through `JobRuntime` and returns a synthetic id (`sync-<hex>`). Because work happens at
enqueue time, there is nothing to pull: `fetch()` always returns `null`, the lease verbs are no-ops
returning `true`, and `reapExpired()` recovers nothing.

```php
// With $worker = 'sync', this runs the command immediately and returns once it finishes.
$id = Jobs::define('command', 'app:report')->dispatch();
```

Use `sync` for local development, tests, and CLI flows where a real broker is undesirable. It is the
default `$worker`.

> **Note:** `maxRetries`, `nack` backoff and the reaper are meaningless for `sync` because there is
> no queue. A failing inline job simply returns a failed `ExecutionResult`.

### database

Persistent relational storage (the `queues` table). Reliable, fully inspectable with plain SQL, and a
good default for most applications that already run a database.

**Storage and claim.** `enqueue()` inserts a row with `status = 'pending'`. `fetch()` atomically
claims one ready row using `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8+, PostgreSQL 9.5+) with an
optimistic-locking fallback (exponential backoff + jitter) for engines that lack `SKIP LOCKED`
(e.g. SQLite). The claim query is:

```sql
SELECT id FROM queues
WHERE queue = ? AND status = 'pending' AND schedule <= ?
  AND (available_at IS NULL OR available_at <= ?)
ORDER BY priority ASC, schedule ASC
LIMIT 1
FOR UPDATE SKIP LOCKED;
```

The claimed row is stamped `status = 'in_progress'`, `reserved_at = now`, `owner_token = <random>`.

**Lease verbs.**

| Verb | Effect on the row |
|------|-------------------|
| `ack` | `status = 'completed'`, clears `reserved_at`/`owner_token`. |
| `nack($delay)` | Requeue **in place**: same row/id, `attempts = attempts + 1`, `available_at = now + delay`, back to `status = 'pending'`. No orphan rows. |
| `abandon` | `status = 'failed'` (retained for audit, never re-fetched). The database backend has no native row-level DLQ. |
| `reapExpired` | Rows left `in_progress` with `reserved_at < now - visibilityTimeout` are returned to `pending` (and `owner_token`/`reserved_at` cleared). |

**Recovery.** Run `jobs:queue:reap <queue>` periodically. The timeout used is
`databaseVisibilityTimeout` (default 300s).

**Configuration.**

```php
public array $database = [
    'group' => null,       // CI4 connection group (null = default)
    'table' => 'queues',
];

public int $databaseVisibilityTimeout = 300; // seconds; > your longest job runtime
```

**Migration.** The table and lease columns (`reserved_at`, `available_at`, `owner_token`) plus the
composite claim index are created by:

```bash
php spark migrate -n "Daycry\Jobs"
```

> **Note:** The `queues` table grows indefinitely (acked rows become `completed`, abandoned rows
> become `failed`). Schedule [`jobs:queue:purge`](COMMANDS.md#jobsqueuepurge) to prune it.

### redis

Requires the `ext-redis` extension. A reliable-queue implementation built on four keys per queue
(prefix `jobs:`):

| Key | Type | Role |
|-----|------|------|
| `{q}-waiting` | LIST | Ready messages (`LPUSH` tail, `RPOPLPUSH` head). |
| `{q}-delayed` | ZSET | Future / delayed messages (score = due unix timestamp). |
| `{q}-processing` | LIST | In-flight messages (atomic move via `RPOPLPUSH`). |
| `{q}-processing-meta` | HASH | `raw payload => {ts, owner}` for visibility/ownership tracking. |

**Fetch.** `fetch()` first promotes any due delayed messages (`{q}-delayed` → `{q}-waiting`) using a
Lua script (atomic, prevents duplicate promotion under concurrent workers; falls back to a `zRem`
gate). It then atomically moves one message from `waiting` to `processing` via `RPOPLPUSH`, stamps a
`{ts, owner}` entry in `processing-meta`, and returns the lease.

**Lease verbs.**

| Verb | Effect |
|------|--------|
| `ack` | Remove the message from `processing` and `processing-meta`. |
| `nack($delay)` | Re-serialize the payload with `attempts + 1`; if `$delay > 0` push it to `{q}-delayed` (due at `now + delay`), otherwise back to `{q}-waiting`. Atomic via `MULTI/EXEC`. |
| `abandon` | Drop from `processing`/`processing-meta` (no retry). |
| `reapExpired` | For every `processing-meta` entry older than the visibility timeout, atomically move the message back to `{q}-waiting`. Returns the count reclaimed. |

**Lease renewal.** A long-running worker can call `renewLease($lease)` to re-stamp the
`processing-meta` timestamp so the reaper does not reclaim a still-running job.

**Recovery.** Run `jobs:queue:reap <queue> --backend redis` periodically. The timeout used is
`redisProcessingVisibilityTimeout` (default 300s).

**Configuration.**

```php
public int $redisProcessingVisibilityTimeout = 300; // seconds; > your longest job runtime
```

The connection is taken from the CodeIgniter `Cache` config (the `redis` handler settings).

> **Note:** `Config\Jobs::$blockingFetch` / `$blockingFetchTimeout` are reserved options describing
> the intent to use blocking reads (`BRPOPLPUSH`). The shipped `jobs:queue:work` worker fetches
> non-blockingly and sleeps `pollInterval` seconds between empty cycles; see
> [Worker Behaviour](CONFIGURATION.md#worker-behaviour).

### beanstalk

Wraps beanstalkd tubes via `pda/pheanstalk` v5. Each queue maps to a beanstalkd **tube**.

**Enqueue.** `enqueue()` `put`s the canonical wire payload onto the tube with a computed delay
(derived from `scheduledAt`) and a **TTR** (time-to-run). The TTR is resolved from the definition's
`timeout`, falling back to `Config\Jobs::$jobTimeout`, then to the backend default (3600s). It
returns the wire `identifier` (stable across requeues), not the volatile beanstalkd job id.

**Fetch.** `fetch()` watches the target tube, ignores all other watched tubes, then
`reserveWithTimeout(5)`. A corrupt body is `bury`-ed so it neither blocks the tube nor is silently
lost.

**Lease verbs.**

| Verb | Effect |
|------|--------|
| `ack` | `delete` the job. |
| `nack($delay)` | Re-serialize with `attempts + 1`, then `delete` the old job and `put` a fresh one with the requested delay. (beanstalkd's native `release` cannot mutate the job body, so delete-and-put is used to preserve the incremented attempt counter.) |
| `abandon` | `bury` the job — beanstalkd's **native dead-letter facility** (buried jobs are retained for inspection/`kick` but never auto-served). |
| `reapExpired` | No-op (returns `0`): beanstalkd auto-returns a reserved job to the ready state once its per-job **TTR** elapses, so no external reaper is needed. |

**Configuration.**

```php
public array $beanstalk = [
    'host' => '127.0.0.1',
    'port' => 11300,
];
```

> **Warning:** The TTR is the beanstalkd-side visibility window. If a job runs longer than its TTR,
> beanstalkd re-serves it to another worker mid-execution. Ensure the resolved TTR
> (`timeout` / `jobTimeout`) is larger than the real runtime.

### serviceBus

Azure Service Bus over HTTP using the peek-lock pattern (SAS authentication). Each queue maps to a
Service Bus queue/entity.

**Enqueue.** `enqueue()` POSTs the canonical wire payload to `/<queue>/messages`. A future
`scheduledAt` is mapped to the `ScheduledEnqueueTimeUtc` broker property.

**Fetch.** `fetch()` POSTs `/<queue>/messages/head?timeout=<lock>` (peek-lock). A `201 Created`
holds the message under a `LockToken`; anything else (`204` and beyond) means no usable message.
The `messageId` + `lockToken` form the settle location (`.../messages/<messageId>/<lockToken>`).

**Lease verbs.**

| Verb | Effect |
|------|--------|
| `ack` | `DELETE` the lock location (complete/settle the message). |
| `nack($delay)` | Service Bus has no native "unlock with delay", so a fresh copy of the wire (with `attempts + 1`) is re-enqueued first — optionally scheduled when `$delay > 0` — and only then is the original lock settled. Enqueue-first/settle-after means a failure never loses the message. |
| `abandon` | Settle (`DELETE`) the lock. The broker dead-letters natively after `MaxDeliveryCount`. |
| `reapExpired` | No-op (returns `0`): the broker releases the peek-lock automatically once `LockedUntilUtc` passes, so crash recovery happens server-side. |

**Configuration.**

```php
public array $serviceBus = [
    'url'    => '', // https://<namespace>.servicebus.windows.net/<queue>
    'issuer' => '', // SAS key name
    'secret' => '', // SAS key value — prefer env('SERVICEBUS_SECRET')
];

public int $serviceBusLockTimeout = 60; // seconds requested when peek-locking
```

> **Warning:** Set `serviceBusLockTimeout` **>= your maximum job runtime**. If the lock expires
> while the job is still running, the broker redelivers the message to another worker.

## Choosing a backend

| Need | Recommended |
|------|-------------|
| Inline execution / development / tests | `sync` |
| Operational transparency, SQL inspection, no extra infrastructure | `database` |
| Fast in-memory processing, delayed messages | `redis` |
| Mature, dedicated tube-based broker | `beanstalk` |
| Cloud-native (Azure), managed broker | `serviceBus` |

## Security

Queue messages are HMAC-signed at enqueue time and verified by the worker, and handlers can be
restricted per queue with `Config\Jobs::$queueHandlers`. See
[Per-queue handler allowlist](advanced.md#per-queue-handler-allowlist) and
[Envelope signing](advanced.md#envelope-signing).

## See also

- [CLI Commands](COMMANDS.md) — `jobs:queue:work`, `jobs:queue:reap`, `jobs:queue:purge`.
- [Operations](operations.md) — running workers in production, the reaper, scaling, observability.
- [Scheduling](scheduling.md) — enqueueing work on a cron schedule.
- [Retries & Backoff](RETRIES.md) — the retry model and backoff strategies.
- [Configuration](CONFIGURATION.md) — every backend and worker setting.
