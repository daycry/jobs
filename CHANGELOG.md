# Changelog

## v2.0.0-alpha - 2026-05-08
### Added (opt-in)
- `Daycry\Jobs\V2\` namespace ships alongside the v1 API. Adopters can migrate
  incrementally; nothing in v1 changes:
  - `JobDefinition` immutable value object replacing the mutable `Job` builder. `withXxx()` returns a fresh copy. `fromLegacyJob()` bridges from the v1 builder.
  - `JobLease` + `QueueBackend` interface unifying `enqueue / fetch / ack / nack / abandon` (no per-instance hidden state).
  - `LegacyWorkerAdapter` wraps any `QueueInterface & WorkerInterface` so worker code can already use the lease API while native v2 backends are being authored.
  - `TypedJobHandler` rehydrates payloads to a declared DTO via reflection so handlers receive typed objects instead of `mixed`.
- `docs/V2_MIGRATION.md` documents the four-step adoption path and the proposed deprecation timeline (v1.2 → v1.3 native → v2.0 → v3.0).

## v1.2.0 - 2026-05-08
### Added
- `Notifications\NotificationService` extracted from `StateTrait::notify()` for DI/testability. Default factory at `Config\Services::jobsNotificationService()` keeps existing callers working unchanged.
- `JobLogger::__construct(?BaseHandler $handler = null, ?string $executionId = null)` accepts injected dependencies.
- `InMemoryMetricsCollector` now caps cardinality with FIFO eviction (default `maxCardinality = 5000`); 24/7 workers no longer grow unbounded.
- `QueueRunCommand::maintenanceTick()` resets in-memory metrics every 1000 iterations and pings/reconnects the database every 100 iterations (survives `wait_timeout`).
- 35 new `FrequenciesTrait` tests close the long-standing gap on cron helper coverage.

### Changed
- `JobLifecycleCoordinator::safeExecuteWithTimeout()` enables `pcntl_async_signals(true)` so SIGALRM interrupts CPU-bound jobs and restores the previous signal handler so successive jobs in the same worker process do not inherit the timeout handler.
- `StateTrait::saveRunningFlag(?int $ttl = null)` accepts a custom TTL; the lifecycle coordinator refreshes the singleInstance lock between attempts so long-running jobs do not lose their lock prematurely (heartbeat).
- `RateLimiter` fallback path simplified; concurrency overshoot is documented (recommend Redis/Memcached for production).

## v1.1.0 - 2026-05-07
### Added
- **ServiceBusQueue peek-lock**: `watch()` issues `POST /<queue>/messages/head?timeout=N` (201 Created) and captures `LockToken`/`MessageId` from `BrokerProperties`. `removeJob(false)` settles via `DELETE /<queue>/messages/<id>/<lockToken>`. `removeJob(true)` enqueues a fresh copy first and settles the original — broker redelivers if the worker crashes mid-flight.
- **RedisQueue reliable-queue pattern**: `RPOPLPUSH` from `<prefix><queue>-waiting` to `<prefix><queue>-processing` plus a `<prefix><queue>-processing-meta` hash with timestamps. `removeJob(true)` moves the item back to waiting via `MULTI`/`EXEC`. New `reapStuckJobs()` method requeues items whose lease exceeded the visibility timeout.
- **`jobs:redis:reap-stuck` CLI command** invokes the reaper for a queue with optional `--timeout` override (defaults to `redisProcessingVisibilityTimeout`).
- **Optional blocking fetch** for Redis (BRPOPLPUSH) and Beanstalk (`reserve_with_timeout`) — opt in via `blockingFetch = true` and `blockingFetchTimeout` config keys. `QueueRunCommand` skips `pollInterval` sleep when the fetch already blocked.
- **`UrlJob` hardening**: explicit `http`/`https` scheme whitelist; `dns_get_record(host, DNS_A | DNS_AAAA)` validates every resolved IP against private/reserved ranges (IPv6 literals included).
- **`ShellJob` realpath whitelist**: compares `realpath()` of candidate against `realpath()` of each whitelist entry. Bare-name entries fall back to the legacy basename match with a deprecation warning (removal in v2.0).
- **`FileHandler` NDJSON**: appends one JSON line per execution with `flock(LOCK_EX)`. `history()` and `lastRun()` transparently read both NDJSON and the legacy JSON-array format. Legacy files are migrated in place on first write.
- **`QueueModel` optimistic locking** ramps to 10 attempts with exponential backoff (10ms→500ms) plus ±20% jitter; bails out early on empty queues.
- New config keys: `blockingFetch`, `blockingFetchTimeout`, `redisProcessingVisibilityTimeout`, `serviceBusLockTimeout`.
- CI workflow now includes a MySQL 8 service so `DatabaseQueue` and the `FOR UPDATE SKIP LOCKED` path are exercised against a real engine alongside Redis.
- 20 new `tests/Unit/V1_1/*` cases covering all v1.1 work.

## v1.0.3 - 2026-05-07
### Fixed
- **`DeadLetterQueue::store()` now returns `bool`** and logs `critical` when DLQ is unconfigured or push fails. `RequeueHelper::finalize()` attempts the DLQ before clearing the origin queue and emits `jobs_dlq_failed` when the move could not be persisted — eliminates silent job loss after retry exhaustion.
- **`RequeueHelper::finalize()` ordering**: `addAttempt()` now runs once per cycle and only after the destination is decided, so failures during requeue do not desynchronise attempts and metrics.
- **`jobs_timed_out` metric** is now emitted on both `pcntl_alarm` and fallback timeout paths in `JobLifecycleCoordinator`.
- **`JobLogger` masking**: bounded recursion (`MAX_MASK_DEPTH = 10`) prevents stack overflow on adversarial deep payloads. The API-key regex now keys on known prefixes (`sk_live_`, `pk_test_`, `AKIA…`, `ghp_…`, `xox[bp]-…`) plus opaque ≥40 chars, so 32-character UUIDs and short identifiers are no longer false positives.
- **`StateTrait::notify()`** fills `runStart` and `duration` placeholders from `ExecutionResult` (were always empty) and logs a warning when `email::send()` returns false.

## v1.0.2 - 2025-11-19
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
- Added `QueueManager` singleton for centralized backend instantiation and caching.
- Added `PayloadSerializerInterface` + `JsonPayloadSerializer` with schema versioning and migration hook.
- Added `InstrumentedQueueDecorator` with queue-level metrics (enqueue/fetch/empty/ack/nack + duration histograms).
- Added `DelayResult` value object (replaces ad-hoc delay integer passing) and `Priority` enum.
- Behavior Change: `removeJob($job, true)` now re-enqueues directly on the same backend instance (Redis, Database, Beanstalk, ServiceBus) instead of delegating to `$job->push()` (prevents cross-backend requeue in multi-worker setups).
- Removed deprecated `BaseQueue::getDelay()` method (use `getDelayResult()->seconds`).
- Removed unused `AttemptsTracker` prototype class (was never wired into retry logic).
- Test Suite: reorganized under `tests/Unit/{Queues,Logging,Retry,Jobs,Execution,Callbacks,Scheduler,Metrics,Helpers,Traits}` for clarity; added decorator tests.
- Documentation: updated `README.md`, `ARCHITECTURE.md`, `CONFIGURATION.md`, `METRICS.md`, `advanced.md`, `COMMANDS.md`, `QUEUE_SIMPLIFICATION.md`, and added `TESTING.md` guide.
- Metrics Documentation: expanded with queue-level metrics table and naming conventions.
- Added direct serializer and instrumentation usage examples to README.

### BREAKING: Esquema de logs extendido
- La tabla de logs (definida por `JobsTables` migration) ahora incluye columnas extendidas: `executionId`, `attempt`, `queue`, `source`, `retryStrategy`, `payloadHash`, `outputLength`, `status`, `data`.
- Se eliminó la migración de reconstrucción `RebuildJobsLogTable` por quedar redundante tras actualizar la migración base.
- Instalaciones nuevas: simplemente ejecutar migraciones y obtendrán el nuevo esquema directamente.
- Instalaciones existentes (antes de esta versión):
	1. Hacer copia de seguridad si desean conservar el histórico.
	2. Eliminar (DROP) manualmente la tabla de logs antigua (`<tableName>` del config `Jobs`).
	3. Ejecutar nuevamente las migraciones (re-creará la tabla con el nuevo layout).
	4. (Opcional) Si no necesitan los datos viejos, pueden omitir el backup.
- El campo `data` almacena el JSON completo original para trazabilidad y futuras expansiones sin alterar el resto de columnas indexadas.

### Observabilidad y Seguridad
- Se añaden y persisten hashes (`payloadHash`) y longitud de salida (`outputLength`) para diagnósticos.
- Enmascarado de claves sensibles configurable vía `$sensitiveKeys` en config `Jobs` (aplica tanto a file logger como a database logger).

### Acción Requerida
Si tenías automatizaciones que asumían el layout antiguo (sin columnas extendidas), actualiza tus consultas SELECT e índices externos.


### Removal Notice
The legacy `Daycry\Jobs\Result` shim has been physically removed. Migrate any remaining usages to the lifecycle-provided `ExecutionResult` (returned internally) and return raw values (scalars/arrays/DTOs) from handlers. Attempting to reference the class will now result in an autoload failure.
