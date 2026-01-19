# Architecture Overview

Core building blocks and how they collaborate.

## Components
| Component | Responsibility |
|-----------|----------------|
| Job | Domain representation: handler key + payload + metadata (name, queue, schedule, attempts). Uses 5 traits for organization. |
| IdentityTrait | Job naming and dependencies (consolidated from NameableTrait + DependableTrait). |
| StateTrait | Job state, notifications, and single-instance flags (consolidated from StatusTrait + NotificableTrait). |
| EnqueuableTrait | Queue-centric behavior: push, attempts tracking, scheduling, worker lookup. |
| CallbackTrait | Enhanced fluent chaining API: `then()`, `catch()`, `finally()`, `chain()` methods. |
| JobEnvelope | Transport snapshot consumed/produced by queue backends. Normalized with `fromBackend()` factory. |
| QueueInterface + Implementations | Backend-specific enqueue / watch / remove operations (Redis, Database, Beanstalk, ServiceBus, Sync). |
| QueueManager | Singleton registry/factory for queue backend instances with caching. |
| PayloadSerializer | Centralized JSON serialization with schema versioning (`JsonPayloadSerializer`). |
| InstrumentedQueueDecorator | Transparent metrics wrapper for any queue backend (7 queue-level metrics). |
| DelayResult | Value object for delay calculation with seconds + scheduledAt timestamp. |
| Priority | Enum for symbolic priorities (LOW/MEDIUM/HIGH → numeric mapping). |
| RequeueHelper | Unified finalization (attempt increment + metrics + DLQ routing + optional requeue). |
| RetryPolicyFixed | Unified retry policy supporting 'none', 'fixed', and 'exponential' strategies. |
| JobLifecycleCoordinator | Orchestrates job execution with retries, notifications, and timeout enforcement. |
| JobExecutor | Safely executes job handlers with error handling. |
| Logger + Handlers | Structured execution record emission with smart token pattern detection. |
| MetricsCollectorInterface | Pluggable counters/histograms exporter. |
| Scheduler | Cron expression parsing & due job dispatching. |
| Commands | Operational CLI interface (Spark commands). |
| **ConfigCache** | **Singleton configuration caching for performance optimization.** |
| **RateLimiter** | **Cache-based token bucket rate limiting per queue.** |
| **DeadLetterQueue** | **Automated routing and statistics for permanently failed jobs.** |
| **HealthCheckCommand** | **System health monitoring with table/JSON output formats.** |

## Execution Flow (Queue)
1. Producer creates `Job` and calls `push()`.
2. Worker loop calls `watch(queue)` obtaining `JobEnvelope`.
3. `JobLifecycleCoordinator::run(Job, 'queue')` orchestrates execution:
   - Reads retry config from global config inline
   - Uses `JobExecutor` for safe handler execution
   - Manages retry loop with delay calculation
   - Triggers notifications based on job settings
4. `RequeueHelper::finalize()` invoked with success flag.
5. Logger records execution (if enabled).
6. Metrics updated.
7. On failure and policy allows, job is requeued.

## Execution Flow (Cron)
1. Scheduler enumerates jobs with cron expressions.
2. Computes due jobs based on current time.
3. Enqueues or directly executes (depending on design) each due job.
4. Logging & attempts semantics apply identically.

## Attempts Integrity
Single authoritative increment in `RequeueHelper` prevents drift. Backends stay stateless about counting.

## Payload Handling
- Validation via `Utils::checkDataQueue()` (ensures necessary fields).
- Masking applied only at logging boundaries (source payload kept intact in memory/queue).

## Security
- **Command Injection Prevention**: `ShellJob` sanitizes inputs using `escapeshellcmd` before execution.
- **Sensitive Data**: Logging system masks configured sensitive keys (passwords, tokens) in payloads and outputs.

## Performance
- **Database Optimization**: Composite index `(status, schedule, priority)` ensures consistent fetch performance.
- **Atomic Operations**: `QueueModel::reserveJob` uses atomic updates to guarantee single-consumer delivery without table locking.

## Extending
1. **Add custom queue**: implement `QueueInterface`, use `JobEnvelope::fromBackend()` factory, register in `$workers` config, access via `QueueManager::instance()->get('name')`.
2. **Add logger**: implement `LoggerHandlerInterface`, register in `$loggers` config.
3. **Add retry algorithm**: Extend `RetryPolicyFixed` or create custom class implementing delay computation logic.
4. **Add metrics**: wrap queue with `InstrumentedQueueDecorator` for automatic 7-metric instrumentation.
5. **Custom serialization**: implement `PayloadSerializerInterface` with schema versioning support.
6. **Custom ID generation**: implement `IdGeneratorInterface` and inject via `BaseQueue::setIdGenerator()`.

## Error Handling
Exceptions during execution should trigger failure path (increment attempt + potential requeue). Ensure try/catch around job run in worker implementation.

## Metrics
Current counters (example): `jobs_succeeded`, `jobs_failed`, `jobs_requeued`. Extend collector to add timing/histogram support around durations or delay distributions.

## Future Enhancements (Ideas)
- Distributed lock for singleton jobs.
- Dead letter queue after max attempts exceeded.
- Rate limiting / concurrency caps per queue.
- Outbox integration for event dispatch.
- OpenTelemetry tracing spans around job execution.
