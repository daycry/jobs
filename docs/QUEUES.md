# Queues & Backends

Multiple backends share a unified abstraction via `QueueInterface` and transport structure `JobEnvelope`.

## Common Operations
| Operation | Method |
|-----------|--------|
| Enqueue | `$job->push()` (delegates to active worker) |
| Consume | `$queue->watch($queueName)` returns `JobEnvelope|null` |
| Remove (complete/fail) | `$queue->removeJob($job, $recreate)` |

## RedisQueue
- Requires `ext-redis`.
- **v1.1+ reliable-queue pattern.** Storage model per queue:
  - `<prefix><queue>-waiting` — LIST of jobs ready to fetch.
  - `<prefix><queue>-delayed` — ZSET of jobs scheduled for the future (score = unix timestamp).
  - `<prefix><queue>-processing` — LIST of in-flight jobs picked up by a worker (atomic `RPOPLPUSH` from waiting).
  - `<prefix><queue>-processing-meta` — HASH of `{ raw_payload => started_at_unix }` consumed by the reaper.
- `watch()` atomically moves one item to processing and records the lease timestamp. `removeJob(false)` drops it; `removeJob(true)` moves it back to waiting via `MULTI`/`EXEC`.
- A worker that crashes between `watch()` and `removeJob()` leaves the item in processing. Run `php spark jobs:redis:reap-stuck --queue=<name>` periodically (e.g. every minute via system cron) so jobs older than `redisProcessingVisibilityTimeout` are returned to the waiting list.
- Set `blockingFetch = true` (config) to use `BRPOPLPUSH` and avoid the `pollInterval` sleep.

## DatabaseQueue
- Stores jobs in a relational table (configured group/table).
- **Atomic Locking** — uses `FOR UPDATE SKIP LOCKED` on MySQL 8+/PostgreSQL 9.5+ when available.
- **Optimistic fallback** (SQLite, MySQL <8, etc.) — v1.1 raised `maxAttempts` from 3 to 10 with exponential backoff (10ms→500ms) plus ±20% jitter, and bails out early on empty queues so a quiet queue does not waste retry budget.
- **High Performance**: composite indexes `(status, schedule, priority)` keep fetch latency independent of history size.
- Enables inspection and manual intervention via SQL.

## BeanstalkQueue
- Wraps Pheanstalk client for beanstalkd tube operations.
- `reserve_with_timeout` already blocks; combine with `blockingFetch = true` so the worker loop skips its own `pollInterval` sleep.

## ServiceBusQueue
- **v1.1+ peek-lock**. `watch()` issues `POST /<queue>/messages/head?timeout=<serviceBusLockTimeout>` and parses `BrokerProperties` (`LockToken` + `MessageId`). The message stays locked at the broker until acked.
- `removeJob(false)` settles via `DELETE /<queue>/messages/<messageId>/<lockToken>`.
- `removeJob(true)` enqueues a fresh copy first, then settles the original — if the worker crashes between, the broker redelivers the original after the lock expires (no data loss).
- Configure `serviceBusLockTimeout` ≥ the maximum expected job runtime; otherwise the broker may redeliver while the worker is still executing.
- The SAS token is generated once per `ServiceBusQueue` instance (week-long lifetime); long-running workers should be restarted periodically until lock-renewal lands in v1.3.

## SyncQueue
- Executes job immediately in the same process (no persistence) — useful for local development or fallback.

## JobEnvelope
Encapsulates transport data: identifier, queue, attempts, payload, timestamps, name. Ensures backend-agnostic worker loop logic.

## Requeue Flow
Use `RequeueHelper::finalize($job, $envelope, $removeFn, $success)` to unify attempts increment + metrics emission + optional requeue.

## Naming
`$job->named('logical_name')` sets a stable name used for logging, pruning and metrics labels.

## Delayed / Scheduled
Jobs can carry a schedule timestamp (depending on implementation) to handle future execution; backends process promotion accordingly.

## Choosing a Backend
| Need | Recommended |
|------|-------------|
| Fast ephemeral processing | Redis |
| Operational transparency | Database |
| Simple & stable legacy | Beanstalk |
| Cloud integration | Service Bus |
| Inline / dev | Sync |

## Queue Management

### QueueManager
The `QueueManager` singleton provides centralized registry and factory for queue backends:
```php
use Daycry\Jobs\Libraries\QueueManager;

// Get default worker
$queue = QueueManager::instance()->getDefault();

// Get specific worker by name
$queue = QueueManager::instance()->get('redis');
```

Configuration is read from `Config\Jobs::$workers` array. Instances are cached per worker name.

### PayloadSerializer
All backends use `PayloadSerializerInterface` for consistent payload serialization:
- **JsonPayloadSerializer**: Default implementation with schema versioning (`_schemaVersion` field)
- Handles legacy payloads without version field transparently
- Extensible via `migrate()` method for future schema upgrades

### Metrics & Observability
Use `InstrumentedQueueDecorator` to transparently add metrics to any queue:
```php
use Daycry\Jobs\Libraries\InstrumentedQueueDecorator;
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;

$metrics = new InMemoryMetricsCollector();
$instrumented = new InstrumentedQueueDecorator(
    queue: $originalQueue,
    metrics: $metrics,
    backendName: 'redis'
);
```

Tracks 7 metrics:
- `queue_enqueue_total`: Successful enqueues by backend/queue
- `queue_fetch_total`: Successful fetches
- `queue_fetch_empty_total`: Empty fetch attempts
- `queue_ack_total`: Job acknowledgments (successful completion)
- `queue_nack_total`: Job negative acknowledgments (requeues)
- `queue_enqueue_duration_seconds`: Enqueue operation duration histogram
- `queue_fetch_duration_seconds`: Fetch operation duration histogram

## Extending
Implement `QueueInterface`, honor `JobEnvelope` structure, and register your worker in `$workers` map.

## v2 (opt-in) — `QueueBackend`
A new lease-oriented contract ships in `Daycry\Jobs\V2\Queues\QueueBackend` (still alongside the v1 split). It exposes `enqueue / fetch / ack / nack / abandon`, removing the per-instance hidden state in legacy backends.

```php
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\V2\JobDefinition;
use Daycry\Jobs\V2\Queues\LegacyWorkerAdapter;

$legacy   = QueueManager::instance()->getDefault();
$adapter  = new LegacyWorkerAdapter($legacy, 'redis', 300);

$id    = $adapter->enqueue((new JobDefinition('command', 'jobs:test'))->withQueue('reports'));
$lease = $adapter->fetch('reports');

if ($lease !== null) {
    try {
        // ... process $lease->envelope->payload ...
        $adapter->ack($lease);
    } catch (\Throwable) {
        $adapter->nack($lease);
    }
}
```

See [V2 Migration](V2_MIGRATION.md) for the full adoption path and timeline.
