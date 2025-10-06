# Architecture Overview

Core building blocks and how they collaborate.

## Components
| Component | Responsibility |
|-----------|----------------|
| Job | Domain representation: handler key + payload + metadata (name, queue, schedule, attempts). |
| EnqueuableTrait | Queue-centric behavior: push, attempts tracking, scheduling, worker lookup. |
| JobEnvelope | Transport snapshot consumed/produced by queue backends. |
| QueueInterface + Implementations | Backend-specific enqueue / watch / remove operations. |
| RequeueHelper | Unified finalization (attempt increment + metrics + optional requeue). |
| Retry Policies | Compute delay/backoff and decide requeue conditions. |
| Logger + Handlers | Structured execution record emission to file or database. |
| MetricsCollectorInterface | Pluggable counters/histograms exporter. |
| Scheduler | Cron expression parsing & due job dispatching. |
| Commands | Operational CLI interface (Spark commands). |

## Execution Flow (Queue)
1. Producer creates `Job` and calls `push()`.
2. Worker loop calls `watch(queue)` obtaining `JobEnvelope`.
3. Job is instantiated/executed (handler-specific logic).
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

## Extending
1. Add custom queue: implement `QueueInterface`, create envelope mapping and register in `$workers`.
2. Add logger: implement handle($level,$message) and register in `$loggers`.
3. Add retry algorithm: extend a policy class or compute delay before requeue.

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
