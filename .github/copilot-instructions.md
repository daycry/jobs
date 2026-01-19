# GitHub Copilot Instructions for Daycry/Jobs

You are an expert AI programming assistant for the `daycry/jobs` library, a CodeIgniter 4 package for managing Cron Jobs and Queues.

## Project Context
- **Framework**: CodeIgniter 4 (PHP 8.2+).
- **Library**: `daycry/jobs`.
- **Purpose**: Unified interface for scheduled tasks (Cron) and background processing (Queues).

## Key Architectural Components
1. **Jobs**: Encapsulate logic (`CommandJob`, `ShellJob`, `ClosureJob`, `EventJob`, `UrlJob`).
2. **Traits**: Job uses 5 consolidated traits (IdentityTrait, StateTrait, EnqueuableTrait, ActivityTrait, FrequenciesTrait, EnvironmentTrait, CallbackTrait).
3. **Queues**: Backend implementations (`DatabaseQueue`, `RedisQueue`, `BeanstalkQueue`, `SyncQueue`, `ServiceBusQueue`).
4. **Execution**:
    - `JobExecutor`: Runs the job logic safely.
    - `JobLifecycleCoordinator`: Orchestrates retries and notifications (simplified signature: `run(Job $job, string $source)`).
    - `RetryPolicyFixed`: Unified retry policy supporting 'none', 'fixed', 'exponential' strategies.
5. **Models**: `QueueModel` handles persistence for `DatabaseQueue` with atomic locking.

## Documentation
- Refer to the `docs/` folder for detailed architectural decisions and configuration guides.
- **QUEUES.md**: Details backend specific behaviors (Atomic locking in Database, Redis flows).
- **ARCHITECTURE.md**: High-level component interaction.

## Tool Usage
- **Context7 MCP**: Use the `context7` MCP server to fetch external documentation or library details if strictly necessary (e.g., CodeIgniter 4 internals, Pheanstalk, Redis extension details).
    - Tool: `mcp_context7_get-library-docs` 
    - Tool: `mcp_context7_resolve-library-id`

## Coding Standards & Best Practices
- **Strict Types**: Always use `declare(strict_types=1);`.
- **Security**:
    - Sanitize inputs for system commands (`ShellJob` uses `escapeshellcmd`).
    - Use prepared statements and Model methods for database access.
    - Mask sensitive data in logs.
- **Concurrency**:
    - Use atomic operations for queue fetching (`reserveJob`).
    - Avoid long-running locks.
- **Performance**:
    - Rely on composite indexes for status queries.
    - Use efficient Redis list operations.
- **Testing**:
    - Write unit tests in `tests/`.
    - Run tests with `composer test`.
    - Mock external services (Redis, Database) where possible.

## Recent Changes (Keep in Mind)
- **DatabaseQueue**: Now uses `reserveJob` for atomic locking preventing race conditions.
- **Database Performance**: Added `idx_queue_fetch` composite index on `(status, schedule, priority)`.
- **ShellJob**: Added input sanitization.
- **Architecture Simplification** (Jan 2026):
  - Removed `ExecutionContext` wrapper - `JobLifecycleCoordinator` now accepts `(Job, string $source)` directly.
  - Removed `CompletionStrategy` pattern - logic consolidated in `RequeueHelper`.
  - Unified `RetryPolicy` classes - `RetryPolicyFixed` now handles all strategies.
  - Consolidated traits from 9 to 5 (`IdentityTrait`, `StateTrait` replace 4 smaller traits).
  - **13 files deleted**, functionality 100% preserved, all tests passing.

When asked to implement features, checking `docs/` first is mandatory to ensure consistency with existing patterns.
