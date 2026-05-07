# Codeigniter Jobs Documentation

Welcome to the Daycry Jobs documentation. This site centralizes guides, architecture notes and usage examples for the advanced job scheduling & queue processing package for CodeIgniter 4.

## Quick Overview
- **Unified Job API**: command, shell, closure, event, URL handlers.
- **Schedulers & Queues**: cron-like scheduling plus multiple queue backends (Redis, Database, Beanstalk, Azure Service Bus, Sync).
- **Reliable backends (v1.1+)**: ServiceBus peek-lock, Redis reliable-queue pattern (RPOPLPUSH + processing list + reaper), optional BRPOPLPUSH/blocking fetch, robust optimistic locking on the Database backend.
- **Hardened handlers (v1.1+)**: UrlJob with scheme whitelist + multi-IP DNS validation (IPv4 + IPv6); ShellJob with realpath-based whitelist.
- **NDJSON file logging (v1.1+)**: append-only writes with deterministic pruning; transparent read of legacy JSON-array files.
- **Fail-loud DLQ (v1.0.3+)**: `DeadLetterQueue::store()` returns bool; `RequeueHelper` attempts the DLQ before clearing the origin and emits `jobs_dlq_failed` if anything goes wrong.
- **Heartbeat singleInstance lock (v1.2+)**: long-running jobs no longer lose their lock prematurely.
- **Worker maintenance (v1.2+)**: DB ping/reconnect every 100 iterations, in-memory metrics reset every 1 000 iterations.
- **Centralized Queue Management**: `QueueManager` singleton for backend access and caching.
- **Schema Versioning**: `PayloadSerializer` with migration support for evolving payload structures.
- **Transparent Metrics**: `InstrumentedQueueDecorator` wraps any queue with 7 automatic metrics.
- **Retries & Backoff**: fixed, exponential, jitter policies with normalized attempt semantics.
- **Structured Logging & Masking**: configurable drivers (file/database) with sensitive key masking; pattern-based detection of JWT/Bearer/known API-key prefixes.
- **Callbacks & Chaining**: post-execution jobs with inheritance and filter conditions.
- **Metrics Layer**: pluggable counters/histograms via `MetricsCollectorInterface`.
- **v2 opt-in API (v2.0-alpha)**: `JobDefinition` immutable + `QueueBackend` lease-based contract — see [V2 Migration](V2_MIGRATION.md).

## Getting Started
1. Install the package:
   ```bash
   composer require daycry/jobs
   ```
2. Publish and run migrations:
   ```bash
   php spark jobs:publish
   php spark migrate -all
   ```
3. Configure queues & worker in `app/Config/Jobs.php`.
4. Run the cron worker:
   ```bash
   php spark jobs:cronjob:run
   ```
5. Run a queue worker:
   ```bash
   php spark jobs:queue:run
   ```

## Key Sections
| Topic | Description |
|-------|-------------|
| Configuration | All tunables (queues, retries, logging, timeouts) |
| Architecture | Internal components and lifecycle flow |
| Queues | Backend capabilities, QueueManager, serialization |
| Queue Simplification | Migration guide for refactored queue subsystem |
| Metrics | Instrumentation, InstrumentedQueueDecorator, custom collectors |
| Logging | Structured log emission and masking |
| Retries | Policies and backoff semantics |
| Attempts | Attempt counting model |
| CLI Commands | Operational control & maintenance |
| Testing | Test organization, running tests, writing tests |

Use the navigation to dive deeper into each area.

## Contributing
Contributions and feedback are welcome. Please open issues or PRs at the GitHub repository.

## License
MIT License. See the full text in the repository.
