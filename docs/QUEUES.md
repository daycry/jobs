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

## Extending
Implement `QueueInterface`, honor `JobEnvelope` structure, and register your worker in `$workers` map.
