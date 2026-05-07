# Architecture Overview

Core building blocks and how they collaborate.

## Components
| Component | Responsibility |
|-----------|----------------|
| Job | Domain representation: handler key + payload + metadata (name, queue, schedule, attempts). Uses 7 traits for organization. |
| IdentityTrait | Job naming, job ID and dependencies (consolidated from NameableTrait + DependableTrait). |
| StateTrait | Job state, single-instance flag, notification gates. The `notify()` body now delegates to `Notifications\NotificationService` (DI-friendly) — the trait keeps just the public flags + helper. |
| EnqueuableTrait | Queue-centric behavior: push, attempts tracking, scheduling, worker lookup. |
| ActivityTrait | `shouldRun()`, `lastRun()` and `getLastRunTime()` — scheduling activity helpers. |
| FrequenciesTrait | Full cron expression builder: `daily()`, `everyMinute()`, `hourly()`, etc. |
| EnvironmentTrait | Environment restriction API: `environments()`, `inEnvironment()`. |
| CallbackTrait | Enhanced fluent chaining API: `then()`, `catch()`, `finally()`, `chain()`, `setCallbackJob()`. |
| JobEnvelope | Transport snapshot consumed/produced by queue backends. Normalized with `fromBackend()` factory. |
| QueueInterface + Implementations | Backend-specific enqueue / watch / remove operations. v1.1 brought ServiceBus peek-lock and the Redis reliable-queue pattern (waiting / processing / processing-meta keys + `reapStuckJobs()`). |
| QueueManager | Singleton registry/factory for queue backend instances with caching. |
| PayloadSerializer | Centralized JSON serialization with schema versioning (`JsonPayloadSerializer`). |
| InstrumentedQueueDecorator | Transparent metrics wrapper for any queue backend (7 queue-level metrics). |
| DelayResult | Value object for delay calculation with seconds + scheduledAt timestamp. |
| Priority | Enum for symbolic priorities (LOW/MEDIUM/HIGH → numeric mapping). |
| RequeueHelper | Unified finalization (attempt increment + metrics + DLQ routing + optional requeue). v1.0.3 reordered the operations so DLQ is attempted before the origin is cleared, eliminating silent loss. |
| RetryPolicyFixed | Unified retry policy supporting 'none', 'fixed', and 'exponential' strategies. |
| JobLifecycleCoordinator | Orchestrates job execution with retries, notifications, timeout enforcement (`pcntl_async_signals(true)` so CPU-bound jobs are interrupted), and the singleInstance lock heartbeat. |
| Logger + Handlers | Structured execution record emission with smart token pattern detection. The v1.1 file handler writes NDJSON (one JSON line per execution) with transparent legacy-format read. |
| Notifications\NotificationService | Email notification logic extracted from `StateTrait` for DI/testability. Default factory at `Config\Services::jobsNotificationService()`. |
| MetricsCollectorInterface | Pluggable counters/histograms exporter. `InMemoryMetricsCollector` enforces a cardinality cap (default 5 000) with FIFO eviction so workers running 24/7 stay bounded. |
| Scheduler | Cron expression parsing & due job dispatching. |
| Commands | Operational CLI interface (Spark commands). |
| ConfigCache | Singleton configuration caching for performance optimization. |
| RateLimiter | Cache-based token bucket rate limiting per queue. |
| DeadLetterQueue | Automated routing and statistics for permanently failed jobs (`store()` returns bool). |
| HealthCheckCommand | System health monitoring with table/JSON output formats. |
| RedisReapStuckCommand | `jobs:redis:reap-stuck` — re-enqueues Redis jobs left in the processing list past `redisProcessingVisibilityTimeout`. |
| **V2 namespace (opt-in, v2.0-alpha)** | `JobDefinition` (immutable), `JobLease` + `QueueBackend` (lease-based contract), `LegacyWorkerAdapter` (bridge), `TypedJobHandler` (DTO-typed payloads). See [V2 Migration](V2_MIGRATION.md). |

## Execution Flow (Queue)
1. Producer creates `Job` and calls `push()`.
2. Worker loop calls `watch(queue)` obtaining `JobEnvelope`.
3. `JobLifecycleCoordinator::run(Job, 'queue')` orchestrates execution:
   - Reads retry config from global config inline
   - Executes handler logic safely (internal try/catch & buffer capture)
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
- **Command injection prevention**: `ShellJob` escapes each argument with `escapeshellarg()` and (v1.1+) compares the candidate's `realpath()` against the whitelist's `realpath()` so `/tmp/echo` cannot impersonate `/usr/bin/echo`.
- **SSRF protection (v1.1+)**: `UrlJob` enforces an `http`/`https` whitelist and validates *every* resolved IP (`dns_get_record(host, DNS_A | DNS_AAAA)`) against private/reserved ranges; IPv6 literals are accepted only when the literal itself is public.
- **Sensitive data**: the logger masks configured keys (passwords, tokens) in payloads and outputs. Pattern-based detection covers JWTs, Bearer tokens and known API-key prefixes (Stripe, AWS, GitHub, Slack); recursion is bounded so adversarial deep payloads cannot trigger a stack overflow.

## Performance
- **Database optimization**: composite index `(status, schedule, priority)` ensures consistent fetch performance.
- **Atomic operations**: `QueueModel::reserveJob` uses `FOR UPDATE SKIP LOCKED` when the engine supports it; the optimistic-locking fallback (v1.1+) uses 10 attempts with exponential backoff + jitter and bails out early on empty queues.
- **Redis reliable queue (v1.1+)**: atomic `RPOPLPUSH` from waiting to processing prevents message loss on worker crashes; `reapStuckJobs()` recovers items past their visibility timeout. Optional `BRPOPLPUSH` blocking fetch eliminates polling latency.
- **NDJSON file logging (v1.1+)**: append-only writes with `flock(LOCK_EX)` are O(1) and survive concurrent worker writes; reads support both NDJSON and the legacy JSON-array format transparently.
- **Worker maintenance (v1.2+)**: every 100 iterations the worker pings the database and reconnects on failure; every 1 000 iterations the in-memory metrics collector is reset to keep memory bounded.

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
Current counters: `jobs_succeeded`, `jobs_failed`, `jobs_requeued`, `jobs_failed_permanently`, `jobs_dlq_failed`, `jobs_timed_out`, `jobs_fetched`, `jobs_age_seconds`, `jobs_exec_seconds`. Extend the collector to add timing/histogram support around durations or delay distributions; see `docs/METRICS.md`.

## Future Enhancements (Ideas)
- Native v2 backends (Redis, Database, ServiceBus) implementing `QueueBackend` directly so the `LegacyWorkerAdapter` becomes optional.
- Distributed lock for singleton jobs (beyond single-node cache lock).
- Outbox integration for transactional event dispatch.
- OpenTelemetry tracing spans around job execution.
- Batch job processing (`$batchSize` config already reserved).
