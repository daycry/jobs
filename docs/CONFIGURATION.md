# Configuration

The package reads its settings from `Daycry\Jobs\Config\Jobs` (you may publish / copy to your app namespace to customize). Below is an overview of the most relevant options.

## Core
| Property | Type | Description |
|----------|------|-------------|
| `$jobs` | array | Mapping of job handler keys (e.g. `command`, `shell`) to concrete job classes. |
| `$queues` | string|array | Comma list or array of queue names available. First is used as default if none chosen. |
| `$worker` | string | Active queue backend key referencing `$workers`. Access via `QueueManager::instance()->get($name)`. |
| `$workers` | array | Map of worker key => queue implementation class. Instances cached by `QueueManager`. |
| `$logPerformance` | bool | Enable/disable structured execution logging. |
| `$log` | string | Logging driver key (`file` or `database`). |
| `$loggers` | array | Map logging driver key => handler class. |
| `$filePath` | string | Directory for file logs (one JSON file per job name). |
| `$maxLogsPerJob` | int | Prune old records (database) or lines (file) beyond this count (file handler may ignore if not implemented). |
| `$sensitiveKeys` | array | List of payload/output/error keys (case-insensitive) to mask recursively. |
| `$maxOutputLength` | ?int | Truncate output & error string length (null = unlimited). |

## Security & Performance
| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$allowedShellCommands` | array | `[]` | Whitelist for `ShellJob`. Empty array = allow all (backward compatible). Entries with a path separator are matched via `realpath()`; bare names use the legacy basename match with a deprecation warning (removal in v2.0). |
| `$queueRateLimits` | array | `[]` | Per-queue rate limits (jobs/minute). Format: `['queue_name' => max_per_minute]`. 0 or missing = no limit. Cache-based token bucket; production deployments should use Redis/Memcached for atomic semantics. |
| `$deadLetterQueue` | ?string | `null` | Queue name for permanently failed jobs. **When `null` and retries exhaust, the helper logs a `critical` and emits `jobs_dlq_failed`.** Configure a queue to avoid silent loss. Metadata appended: `dlq_reason`, `dlq_timestamp`, `dlq_attempts`, `original_queue`. |
| `$jobTimeout` | int | `300` | Maximum execution time per job in seconds. 0 = disabled. Uses `pcntl_alarm` (with `pcntl_async_signals(true)` so CPU-bound jobs get interrupted), falls back to a post-execute time check on Windows/non-pcntl runtimes. Emits `jobs_timed_out` on either path. |
| `$batchSize` | int | `1` | Reserved for future batch processing feature. Currently unused. |

### Worker behaviour
| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$pollInterval` | int | `5` | Seconds to sleep between polling cycles when no job is available. Skipped automatically when `$blockingFetch` is enabled and the active backend supports blocking reads. |
| `$blockingFetch` | bool | `false` | Opt in to blocking fetch on backends that support it (Redis `BRPOPLPUSH`, Beanstalk `reserve_with_timeout`). Eliminates the polling sleep latency. |
| `$blockingFetchTimeout` | int | `5` | Seconds to wait per blocking fetch (also acts as the upper bound for graceful shutdown latency). |
| `$redisProcessingVisibilityTimeout` | int | `300` | Visibility timeout (seconds) used by `jobs:redis:reap-stuck` to decide when an in-flight job in the Redis processing list belongs to a crashed worker and must be requeued. |
| `$serviceBusLockTimeout` | int | `60` | Lock timeout (seconds) requested when peek-locking Service Bus messages. Should be ≥ the maximum job runtime; otherwise the broker may redeliver mid-execution. |
| `$circuitBreakerThreshold` | int | `5` | Consecutive backend failures before the circuit opens. |
| `$circuitBreakerCooldown` | int | `60` | Seconds the circuit stays open before the worker retries the backend. |

## Retry / Backoff
| Property | Type | Notes |
|----------|------|-------|
| `$retryBackoffStrategy` | string | `none`, `fixed`, `exponential`. |
| `$retryBackoffBase` | int | Base delay (seconds) for first retry. |
| `$retryBackoffMultiplier` | float | Exponential factor: `delay = base * multiplier^(attempt-2)` (attempt=2 = first retry) |
| `$retryBackoffMax` | int | Upper cap on any computed delay. |
| `$retryBackoffJitter` | bool | Add +/- up to 15% random jitter. |

## Timeout
| Property | Type | Description |
|----------|------|-------------|
| `$defaultTimeout` | ?int | Global timeout seconds for jobs lacking explicit timeout. (Enforcement dependent on execution wrapper.) |

## Backend Specific
### Database
| Property | Path | Description |
|----------|------|-------------|
| `$database` | `['group','table']` | Connection group + table for queue storage. |

### Redis
Relies on `ext-redis` and host/port taken from environment (e.g. `REDIS_HOST`, `REDIS_PORT`).

### Beanstalk
| Key | Default |
|-----|---------|
| `host` | 127.0.0.1 |
| `port` | 11300 |

### Azure Service Bus
| Key | Description |
|-----|-------------|
| `url` | Full queue endpoint. |
| `issuer` | SAS key name. |
| `secret` | SAS key value. |

## Sensitive Data Masking
The effective keys are the union of internal defaults (`password`, `token`, `secret`, `authorization`, `api_key`) plus any configured in `$sensitiveKeys` and those appended dynamically at runtime. Matching is case-insensitive and recursive across arrays/objects. Values are replaced with `***`.

### Enhanced Token Pattern Detection (v1.0.3+)
In addition to key-based masking, the logger automatically detects and masks:
- **JWT Tokens**: Format `xxx.yyy.zzz` → `***JWT_TOKEN***`
- **API Keys**: known provider prefixes (`sk_(live|test)_…`, `pk_(live|test)_…`, `AKIA…` (AWS), `gh[pousr]_…` (GitHub PATs), `xox[abprs]-…` (Slack)) **or** opaque alphanumeric strings of 40 characters or more → `***API_KEY***`. The previous "32+ chars" rule was tightened in v1.0.3 so 32-character UUIDs and SHA-1 hex digests are no longer false positives.
- **Bearer Tokens**: `Bearer <token>` → `Bearer ***TOKEN***`

Recursion is bounded by `MAX_MASK_DEPTH = 10`; payloads nested deeper than that are replaced by `[truncated:max-depth]` so adversarial deep arrays/objects cannot cause a stack overflow.

This pattern-based detection works independently of key names, providing defense-in-depth for leaked credentials.

## Security Features

### Shell Command Whitelisting
Restrict which shell commands can be executed by `ShellJob`. **Recommended in v1.1+:** use absolute paths so `/tmp/echo` cannot impersonate a whitelisted `/usr/bin/echo`:

```php
// Recommended (v1.1+): absolute paths matched via realpath()
public array $allowedShellCommands = ['/usr/bin/ls', '/usr/bin/grep', '/usr/bin/cat'];

// Legacy (deprecated, still works with a warning log)
public array $allowedShellCommands = ['ls', 'grep', 'cat'];
```

- Empty array (default): All commands allowed (backward compatible).
- Entries with a path separator (`/` or `\\`) are resolved with `realpath()` and compared against the resolved candidate. `/tmp/echo` is rejected even if the whitelist contains `/usr/bin/echo`.
- Bare names fall back to the legacy `basename()` match and emit a deprecation warning. This mode is removed in v2.0.
- Throws `JobException::forShellCommandNotAllowed()` on violation.

### Rate Limiting
Prevents queue overload with per-queue limits:
```php
public array $queueRateLimits = [
    'high_priority' => 100,  // Max 100 jobs/minute
    'default' => 50,         // Max 50 jobs/minute
];
```
Implementation:
- Cache-based token bucket algorithm
- Worker skips processing when limit exceeded
- Use `RateLimiter` class for programmatic access:
  ```php
  $limiter = new \Daycry\Jobs\Libraries\RateLimiter();
  if ($limiter->allow('default', 50)) {
      // Process job
  }
  ```

### Dead Letter Queue (DLQ)
Automatic routing of permanently failed jobs for forensic analysis:
```php
public ?string $deadLetterQueue = 'failed_jobs';
```
Behavior (v1.0.3+):
- Jobs exceeding max retries are moved to the DLQ **before** being cleared from the origin queue, so a DLQ failure does not lose the message.
- `DeadLetterQueue::store()` returns `bool` — `false` means the DLQ was unconfigured or the underlying push failed; `RequeueHelper` then emits the `jobs_dlq_failed` metric so operators can alert on it.
- Without `$deadLetterQueue`, permanently failed jobs are still removed from the origin (otherwise some backends — Redis processing list, ServiceBus settle — would loop forever) and a `critical` log entry is recorded.
- Metadata added when stored: `dlq_reason`, `dlq_timestamp`, `dlq_attempts`, `original_queue`.
- Retrieve stats: `$dlq->getStats()`.

### Job Timeout Protection
Hard enforcement of maximum execution time:
```php
public int $jobTimeout = 300; // 5 minutes
```
- 0 = disabled (backward compatible)
- Uses `pcntl_alarm()` for signal-based timeout (kills runaway processes)
- Fallback to time check if `pcntl` extension unavailable
- Throws `JobException::forJobTimeout()` when exceeded

## Queue Management

### QueueManager (Centralized Registry)
Access queue backends via singleton:
```php
use Daycry\Jobs\Libraries\QueueManager;

// Get default worker
$queue = QueueManager::instance()->getDefault();

// Get specific worker by name
$queue = QueueManager::instance()->get('redis');

// List all configured workers
$workers = QueueManager::instance()->list();
```

### PayloadSerializer (Schema Versioning)
All queues use centralized serialization:
```php
use Daycry\Jobs\Libraries\JsonPayloadSerializer;

$serializer = new JsonPayloadSerializer(schemaVersion: 2);
$queue->setSerializer($serializer);
```

### InstrumentedQueueDecorator (Metrics)
Wrap any queue for transparent metrics:
```php
use Daycry\Jobs\Libraries\InstrumentedQueueDecorator;
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;

$metrics = new InMemoryMetricsCollector();
$instrumented = new InstrumentedQueueDecorator(
    queue: $queue,
    metrics: $metrics,
    backendName: 'redis'
);
// Tracks: enqueue_total, fetch_total, ack_total, nack_total, durations
```

## Publishing / Overriding
Copy `src/Config/Jobs.php` into `app/Config/Jobs.php` and modify. CodeIgniter's service locator will favor the application namespace.

## Minimal Example
```php
$cfg = config('Jobs');
$cfg->queues = 'default,mail';
$cfg->worker = 'redis';
$cfg->logPerformance = true;
$cfg->sensitiveKeys[] = 'access_token';
$cfg->retryBackoffStrategy = 'exponential';
$cfg->retryBackoffBase = 3;
$cfg->retryBackoffMultiplier = 2.5;
$cfg->retryBackoffMax = 180;
```
