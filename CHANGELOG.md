# Changelog

## Unreleased
- Added JobTestCommandTest to cover custom test CLI command and integration touchpoint in QueuePushTest.
- Renamed test CLI command to `jobs:test` (alias `job:test` retained) to align with existing invocation attempts and remove warning noise.
- BREAKING: Removed unused `WorkerInterface::getDataJob()` method and all queue-specific implementations (DatabaseQueue, RedisQueue, BeanstalkQueue, ServiceBusQueue). Payload hydration will be redesigned under a future JobEnvelope abstraction.
- Introduced and fully adopted `JobEnvelope` DTO across all queue backends (Database, Redis, Beanstalk, ServiceBus) as normalized worker output.
- Added factory helpers `JobEnvelope::fromDecoded()` and `JobEnvelope::fromJob()`; standardized attempts extraction across queues.
- Added `createdAt` field to `JobEnvelope` and populated across all queue backends (Database, Redis, Beanstalk, ServiceBus) to enable age and latency metrics.
- Introduced lightweight metrics subsystem (`MetricsCollectorInterface`, `InMemoryMetricsCollector`) with counters and basic histogram aggregation.
- Instrumented `QueueRunCommand` with fetch/success/fail/requeue counters plus execution and age latency observations.
- Added `RequeueHelper` centralizing success/failure finalization logic (removes duplicated enqueue/remove branches per outcome).
- Removed legacy mixed payload branch from `QueueRunCommand`; it now exclusively handles `JobEnvelope` instances (BREAKING for custom queues still returning raw payloads).
- Added watch tests for Database, Beanstalk, and ServiceBus queues ensuring `JobEnvelope` contract coverage.

### Removal Notice
The legacy `Daycry\Jobs\Result` shim has been physically removed. Migrate any remaining usages to the lifecycle-provided `ExecutionResult` (returned internally) and return raw values (scalars/arrays/DTOs) from handlers. Attempting to reference the class will now result in an autoload failure.
