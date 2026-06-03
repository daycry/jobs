# Queues & Backends

In v3 every backend implements a single, lease-oriented contract:
`Daycry\Jobs\Queues\QueueBackend`. The worker fetches a lease, runs **one** attempt, then settles
it with `ack`, `nack` (requeue with backoff) or `abandon` (dead-letter). Delivery is
**at-least-once** for persistent backends, so handlers should be idempotent — see
[Advanced](advanced.md#idempotency).

## The `QueueBackend` contract

```php
namespace Daycry\Jobs\Queues;

interface QueueBackend
{
    // Persist the definition; returns the backend-assigned id.
    public function enqueue(JobDefinition $definition): string;

    // Lease one ready message, or null when the queue is empty.
    public function fetch(string $queue): ?JobLease;

    // Mark the leased message processed; remove it permanently.
    public function ack(JobLease $lease): bool;

    // Processing failed — redeliver, optionally after $delaySeconds (retry backoff).
    public function nack(JobLease $lease, ?int $delaySeconds = null): bool;

    // Stop without retrying (dead-letter on backends that support it; else equivalent to ack).
    public function abandon(JobLease $lease): bool;

    // Reclaim messages whose lease expired (crashed worker). Returns the count recovered.
    public function reapExpired(string $queue, int $visibilityTimeout): int;
}
```

A `JobLease` carries the in-flight `JobEnvelope`, an opaque backend `token`, a worker-minted
`ownerToken` (so a reaped-and-reassigned message cannot be acked by the previous owner), a visibility
`expiresAt` deadline, and the backend name. The backend never holds per-message state itself: the
worker passes the lease back to ack/nack/abandon.

## Configuring backends

Backends are declared in `Config\Jobs::$backends` (name => class) and the default is set by
`Config\Jobs::$worker`:

```php
public array $backends = [
    'sync'       => SyncBackend::class,
    'database'   => DatabaseBackend::class,
    'redis'      => RedisBackend::class,
    'beanstalk'  => BeanstalkBackend::class,
    'serviceBus' => ServiceBusBackend::class,
];

public string $worker = 'sync'; // default backend used when none is specified
```

Available queue names come from `Config\Jobs::$queues` (a comma-separated string or a list). The
worker defaults to the first configured queue when you do not name one.

## Enqueueing

Use the fluent builder and call `dispatch()`. With no argument it uses the default backend
(`$worker`); pass a backend name to override:

```php
use Daycry\Jobs\Jobs;

// Onto the default backend
$id = Jobs::define('command', 'app:report')
    ->queue('reports')
    ->maxRetries(3)
    ->dispatch();

// Onto a specific backend
$id = Jobs::define('command', 'app:report')
    ->queue('reports')
    ->dispatch('database');
```

You can also resolve a backend directly and call its contract:

```php
use Daycry\Jobs\Jobs;

$backend = Jobs::backend('redis');   // or Jobs::backend() for the default
$id      = $backend->enqueue(
    Jobs::define('command', 'app:report')->queue('reports')->toDefinition(),
);
```

## Running a worker

`jobs:queue:work` pulls from the backend and drives each message through the pipeline:

```text
fetch -> verify signature -> idempotency guard -> run ONE attempt -> ack / nack(backoff) / abandon
```

```bash
# Long-running worker on the 'reports' queue using the default backend
php spark jobs:queue:work reports

# One cycle then exit (cron-friendly)
php spark jobs:queue:work reports --once

# Bound the number of cycles, and override the backend
php spark jobs:queue:work reports --max 100 --backend redis
```

The worker is resilient by design: graceful shutdown on SIGTERM/SIGINT (finishes the current cycle,
never aborts a job mid-flight), a per-queue circuit breaker, and per-queue rate limits
(`Config\Jobs::$queueRateLimits`). See [CLI Commands](COMMANDS.md) for all options.

### Retries and dead-lettering

The retry decision lives in the worker, not the runtime: a job runs at most `maxRetries + 1` times.

- **Success** -> `ack` (message removed).
- **Failure with retries left** -> `nack($lease, $delay)` where `$delay` comes from the configured
  backoff policy; the backend requeues the message in place after the delay.
- **Failure with retries exhausted** -> `abandon($lease)` (dead-letter on capable backends; logged
  as critical).

Backoff is configured in `Config\Jobs` (`retryBackoffStrategy` = `none` | `fixed` | `exponential`,
`retryBackoffBase`, `retryBackoffMultiplier`, `retryBackoffMax`, `retryBackoffJitter`). See
[Retries](RETRIES.md).

## Recovering crashed workers (reaper)

If a worker crashes between `fetch()` and `ack()`, its message stays leased and invisible. The
reaper returns messages whose visibility timeout elapsed back to the ready state:

```bash
php spark jobs:queue:reap reports
php spark jobs:queue:reap reports --backend redis
```

Run it periodically (e.g. every minute) via system cron for the **database** and **redis** backends.
**Beanstalk** and **Service Bus** recover in-flight messages natively when their lock/lease expires,
so the explicit reaper is only needed for DB/Redis. The visibility timeout used is
`redisProcessingVisibilityTimeout` for redis and `databaseVisibilityTimeout` for every other backend
(both default to 300s) — set it higher than your longest expected job runtime so live jobs are not
reclaimed.

## Backends

### sync

Synchronous, in-process. `enqueue()` runs the job inline through `JobRuntime` and returns a synthetic
id; `fetch()` always returns `null` and the lease verbs are no-ops. Ideal for local development and
tests. This is the default `$worker`.

### database

Persistent relational storage. Uses `FOR UPDATE SKIP LOCKED` where available with an optimistic
fallback, and supports `reapExpired()` (returns rows left `in_progress` past
`databaseVisibilityTimeout` to `pending`). Configure the connection group/table via
`Config\Jobs::$database`. Use `jobs:queue:purge` for table maintenance.

### redis

Requires `ext-redis`. Reliable-queue pattern (atomic move to a processing list + ownership token).
Honours `nack(delay)` via a delayed set and recovers crashed leases with `jobs:queue:reap`
(visibility timeout = `redisProcessingVisibilityTimeout`). Set `Config\Jobs::$blockingFetch = true`
to use blocking reads (`BRPOPLPUSH`) and skip the `pollInterval` sleep.

### beanstalk

Wraps beanstalkd tubes. `nack(delay)` maps to a native release-with-delay; in-flight messages are
recovered natively when the reservation TTL elapses. Configure host/port via
`Config\Jobs::$beanstalk`. Supports blocking reads (`reserve-with-timeout`) when
`blockingFetch = true`.

### serviceBus

Azure Service Bus via peek-lock. `nack(delay)` maps to a scheduled re-enqueue; the broker redelivers
natively when the lock expires. Configure `Config\Jobs::$serviceBus` (`url`, `issuer`, `secret` —
prefer `env('SERVICEBUS_SECRET')`) and set `serviceBusLockTimeout` >= your maximum job runtime so the
broker does not redeliver mid-execution.

## Choosing a backend

| Need | Recommended |
|------|-------------|
| Inline / dev / tests | `sync` |
| Operational transparency, SQL inspection | `database` |
| Fast in-memory processing | `redis` |
| Mature tube-based broker | `beanstalk` |
| Cloud-native (Azure) | `serviceBus` |

## Security

Queue messages are HMAC-signed at enqueue time and verified by the worker, and handlers can be
restricted per queue. See [Advanced](advanced.md) for envelope signing and the per-queue handler
allowlist (`Config\Jobs::$queueHandlers`).

Next: [CLI Commands](COMMANDS.md)
