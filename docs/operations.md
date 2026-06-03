# Operations

This page covers running `daycry/jobs` v3 in production: keeping long-running workers alive under a
process supervisor, shutting them down cleanly, scaling out to many workers, running the periodic
reaper, the operational behaviour of the circuit breaker and rate limiter, the dead-letter queue,
and observability via the metrics collector.

It assumes you understand the queue model from [Queues & Backends](QUEUES.md) and the worker
commands from [CLI Commands](COMMANDS.md).

## Running long-running workers

In production you run one or more `jobs:queue:work` processes per queue, each kept alive by a process
supervisor so it restarts automatically on exit (crash, deploy, OOM, or a graceful stop). The worker
itself runs an unbounded loop when invoked without `--once`/`--max`.

### Supervisor

A typical `supervisord` program. `numprocs` runs several identical workers against the same queue
(see [Scaling out](#scaling-out)):

```ini
[program:jobs-reports]
command=php /var/www/app/spark jobs:queue:work reports --backend redis
directory=/var/www/app
user=www-data
numprocs=4
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
startsecs=3
stopsignal=TERM
stopwaitsecs=3600
stdout_logfile=/var/log/jobs/reports.out.log
stderr_logfile=/var/log/jobs/reports.err.log
```

> **Warning:** Set `stopwaitsecs` (Supervisor) **higher than your longest job runtime**. The worker
> finishes the in-flight job before exiting on `SIGTERM`; if the supervisor force-kills it first
> (`SIGKILL`) the job is interrupted and will be redelivered after its visibility timeout.

### systemd

A templated unit (`jobs-worker@.service`) so you can start one instance per queue with
`systemctl start jobs-worker@reports`:

```ini
[Unit]
Description=Jobs queue worker (%i)
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php /var/www/app/spark jobs:queue:work %i --backend redis
Restart=always
RestartSec=3
# Allow the in-flight job to finish before SIGKILL on stop/restart.
TimeoutStopSec=3600
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now jobs-worker@reports
systemctl enable --now jobs-worker@emails
```

> **Note:** Restart your workers on every deploy. A long-running PHP process keeps the old code (and
> a warm `Config\Jobs`) in memory until it restarts, so new handler code or config changes are not
> picked up by a worker that keeps running across the deploy.

## Graceful shutdown and signals

The worker installs handlers for `SIGTERM` and `SIGINT` (POSIX, requires the `pcntl` extension).
On receipt it:

1. Prints `stop signal received, finishing current cycle...`.
2. Sets an internal stop flag (checked at the top of every loop iteration).
3. Finishes the **current** cycle — an in-flight job is run to completion and settled normally.
4. Prints `graceful shutdown complete.` and exits with `SUCCESS`.

This means a deploy/restart never aborts a running job mid-flight; the worker simply stops pulling
new work and exits.

> **Warning:** On platforms without `pcntl` (notably Windows), the worker cannot trap signals.
> Bound such runs with `--once` or `--max N` and re-invoke from a scheduler, or stop the process
> externally between cycles. Always give your supervisor enough stop-grace time
> (`stopwaitsecs` / `TimeoutStopSec`) to exceed the longest job runtime.

## Scaling out

Because the queue contract is lease-based and claims are atomic, you scale throughput simply by
running **more workers** against the same queue:

- The **database** backend claims rows with `FOR UPDATE SKIP LOCKED` (optimistic fallback for SQLite),
  so concurrent workers never grab the same row.
- The **redis** backend moves messages atomically with `RPOPLPUSH` into a per-message processing
  entry, so a message is leased by exactly one worker.
- **beanstalk** and **serviceBus** reserve/peek-lock each message server-side.

Run dedicated worker pools per queue so a slow queue does not starve a fast one:

```bash
# 4 workers on 'emails', 2 on 'reports'
php spark jobs:queue:work emails  --backend redis   # x4 under the supervisor
php spark jobs:queue:work reports --backend redis   # x2 under the supervisor
```

> **Warning:** With multiple workers, delivery is **at-least-once** and a message can be processed
> more than once (after a crash + reaper recovery, or a lease expiry). Make handlers idempotent —
> use `idempotencyKey()` for built-in de-duplication. See [Idempotency](advanced.md#idempotency-in-depth).

## The periodic reaper

A worker that crashes between `fetch()` and `ack()` leaves its message leased and invisible until the
visibility timeout elapses. Run `jobs:queue:reap <queue>` periodically (every minute is typical) to
return such messages to the ready state. This is required for the **database** and **redis** backends;
beanstalk and Service Bus recover natively.

```bash
# System cron, once a minute per queue
* * * * * cd /var/www/app && php spark jobs:queue:reap reports >> /dev/null 2>&1
* * * * * cd /var/www/app && php spark jobs:queue:reap emails --backend redis >> /dev/null 2>&1
```

The visibility timeout used is `redisProcessingVisibilityTimeout` for the redis backend and
`databaseVisibilityTimeout` otherwise (both default 300s).

> **Warning — visibility timeout must exceed runtime.** If a job's real runtime can exceed the
> visibility timeout, the reaper (or the broker, for beanstalk TTR / Service Bus lock) will treat the
> still-running worker as crashed and redeliver the message, causing a duplicate execution. Always
> set the visibility timeout (and beanstalk TTR / `serviceBusLockTimeout`) **greater than your
> longest expected job runtime**, with headroom. For redis, a long-running worker can also extend its
> lease by calling `RedisBackend::renewLease()`.

## Circuit breaker

The worker wraps each cycle in a per-queue `CircuitBreaker` (cache-backed, so state persists across
worker restarts). It protects an unhealthy backend from being hammered:

- **Closed** (normal): failures are counted. After `Config\Jobs::$circuitBreakerThreshold`
  consecutive backend errors the circuit **opens**.
- **Open**: cycles are skipped for `Config\Jobs::$circuitBreakerCooldown` seconds (the worker logs
  `[Circuit Open] ...` and idles `pollInterval`). After the cooldown it allows one probe (half-open).
- **Half-open**: a successful cycle closes the circuit; a failed probe re-opens it.

```php
// Config\Jobs
public int $circuitBreakerThreshold = 5;   // consecutive failures before opening
public int $circuitBreakerCooldown  = 60;  // seconds the circuit stays open
```

> **Note:** The breaker reacts to **thrown backend errors** during a cycle (e.g. the broker is
> unreachable), not to ordinary job failures — a job that runs and fails is nacked/abandoned by the
> pipeline and counts as a *successful* backend cycle for the breaker.

## Rate limiting

Cap how many jobs a queue processes per minute with `Config\Jobs::$queueRateLimits` (jobs/minute,
`0` = unlimited). The worker checks the limit before each cycle and, when throttled, logs
`[Rate Limited] ...` and idles for `pollInterval`.

```php
// Config\Jobs
public array $queueRateLimits = [
    'emails'  => 100, // at most 100 email jobs/minute
    'reports' => 10,
];
```

The limiter (`Daycry\Jobs\Libraries\RateLimiter`) uses a cache-based, per-minute token bucket.

> **Note:** Use an **atomic cache driver (Redis or Memcached)** in production. With those, the
> increment is server-side atomic and the cap is enforced precisely. The file/dummy fallback is
> best-effort and may overshoot by one per racing worker.

## Dead-letter queue

The DLQ holds jobs that have permanently failed so they can be inspected or replayed instead of being
lost. Configure a queue name:

```php
// Config\Jobs
public ?string $deadLetterQueue = 'dead-letter'; // null disables the DLQ helper
```

Routing is provided by `Daycry\Jobs\Libraries\DeadLetterQueue::store($payload, $handler, $reason, $attempts)`,
which enqueues the failed payload (annotated with `_dlq_metadata`: reason, timestamp, attempts) onto
the configured queue using the default backend, and returns `false` when the DLQ is unconfigured or
the enqueue fails.

```php
use Daycry\Jobs\Libraries\DeadLetterQueue;

$stored = (new DeadLetterQueue())->store(
    payload: $failedPayload,
    handler: 'command',
    reason:  'connection timeout',
    attempts: 4,
);

if (! $stored) {
    // DLQ disabled or enqueue failed — decide whether to drop or requeue; never silently lose work.
}
```

> **Warning:** In the current worker pipeline, retry exhaustion calls the backend's `abandon()`
> directly — which routes to a **native** dead-letter facility where the backend has one (beanstalk
> `bury`, Service Bus dead-letter after `MaxDeliveryCount`) and otherwise marks the message `failed`
> (database) or drops it (redis). The `DeadLetterQueue` helper and `$deadLetterQueue` config are an
> **opt-in application-level** facility you invoke yourself; they are not automatically called by the
> worker on abandon. For redis, in particular, configure your own DLQ handling (or rely on
> inspection) so permanently-failed messages are not lost. See also
> [Retries & Backoff](RETRIES.md#dead-letter-queue).

## Observability and metrics

The worker emits counters through a pluggable `Daycry\Jobs\Metrics\MetricsCollectorInterface`,
resolved from `Config\Jobs::$metricsCollector`:

```php
// Config\Jobs
// InMemoryMetricsCollector (default) is fine for dev; null disables all metrics.
public ?string $metricsCollector = InMemoryMetricsCollector::class;
```

The interface is small:

```php
interface MetricsCollectorInterface
{
    public function increment(string $counter, int $value = 1, array $labels = []): void;
    public function observe(string $metric, float $value, array $labels = []): void;
    public function getSnapshot(): array;
}
```

### Counters emitted by the worker

Every counter carries a `queue` label.

| Counter | Incremented when |
|---------|------------------|
| `jobs_fetched` | A message was leased from the backend. |
| `jobs_rejected_signature` | A message failed HMAC signature verification (then abandoned). |
| `jobs_skipped_idempotent` | A message was skipped because its idempotency key was already processed. |
| `jobs_succeeded` | A job ran successfully and was acked. |
| `jobs_failed` | A job attempt failed (before deciding requeue vs dead-letter). |
| `jobs_requeued` | A failed job had retries left and was nacked with backoff. |
| `jobs_failed_permanently` | A failed job exhausted its retries and was abandoned. |

### Reading metrics

The default `InMemoryMetricsCollector` aggregates counters/histograms in process memory (with a
cardinality cap and FIFO eviction so a long-running worker cannot grow unbounded). Read a snapshot:

```php
use Daycry\Jobs\Metrics\Metrics;

$snapshot = Metrics::get()?->getSnapshot();
// ['counters' => ['jobs_succeeded|queue=reports' => 42, ...], 'histograms' => [...]]
```

> **Note:** In-memory metrics live only for the lifetime of one worker process and are not scraped
> across processes. For production monitoring (e.g. Prometheus), implement
> `MetricsCollectorInterface` with an exporter that writes to a shared, scrapeable store — for
> example a Redis/StatsD-backed collector or a Prometheus pushgateway client — and point
> `Config\Jobs::$metricsCollector` at it. Set the config to `null` to disable metrics entirely (all
> `increment`/`observe` calls become no-ops).

In addition to metrics, the worker logs operational events through CodeIgniter's logger: rejected
signatures and retry exhaustion are logged at `critical`, and backend errors surface as CLI error
output. Aggregate these logs centrally to alert on `jobs_failed_permanently` and signature
rejections.

## See also

- [Queues & Backends](QUEUES.md) — backend semantics and recovery model.
- [CLI Commands](COMMANDS.md) — `jobs:queue:work`, `jobs:queue:reap`, `jobs:queue:purge`.
- [Retries & Backoff](RETRIES.md) — retry budget and the dead-letter relationship.
- [Configuration](CONFIGURATION.md) — every operational setting referenced here.
- [Scheduling](scheduling.md) — the cron runner that feeds queued work.
