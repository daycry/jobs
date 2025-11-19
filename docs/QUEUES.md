# Queues & Backends

Multiple backends share a unified abstraction via `QueueInterface` and transport structure `JobEnvelope`.

## Common Operations
| Operation | Method |
|-----------|--------|
| Enqueue | `$job->push()` (delegates to active worker) |
| Consume | `$queue->watch($queueName)` returns `JobEnvelope|null` |
| Remove (complete/fail) | `$queue->removeJob($job, $recreate)` |

## RedisQueue
- Uses Redis lists / sorted sets for delayed jobs.
- Promotes delayed jobs to ready list ensuring minimum positive delay.
- Lightweight and fast; requires `ext-redis`.

## DatabaseQueue
- Stores jobs in relational table (configured group/table).
- Enables inspection and manual intervention via SQL.
- Slower than in-memory backends but stable for small workloads.

## BeanstalkQueue
- Wraps Pheanstalk client for beanstalkd tube operations.
- Good for simple work queue semantics.

## ServiceBusQueue
- Integrates with Azure Service Bus REST endpoints.
- Suitable for cloud distributed producers/consumers.

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
