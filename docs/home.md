# Codeigniter Jobs Documentation

Welcome to the Daycry Jobs documentation. This site centralizes guides, architecture notes and usage examples for the advanced job scheduling & queue processing package for CodeIgniter 4.

## Quick Overview
- **Unified Job API**: command, shell, closure, event, URL handlers.
- **Schedulers & Queues**: Cron-like scheduling plus multiple queue backends (Redis, Database, Beanstalk, Azure Service Bus, Sync).
- **Centralized Queue Management**: `QueueManager` singleton for backend access and caching.
- **Schema Versioning**: `PayloadSerializer` with migration support for evolving payload structures.
- **Transparent Metrics**: `InstrumentedQueueDecorator` wraps any queue with 7 automatic metrics.
- **Retries & Backoff**: Fixed, exponential, jitter policies with normalized attempt semantics.
- **Structured Logging & Masking**: Configurable drivers (file/database) with sensitive key masking.
- **Callbacks & Chaining**: Post-execution jobs with inheritance and filter conditions.
- **Metrics Layer**: Pluggable counters/histograms via `MetricsCollectorInterface`.
- **Clean Architecture**: Value objects (DelayResult, Priority), factories (JobEnvelope::fromBackend), decorators.

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
