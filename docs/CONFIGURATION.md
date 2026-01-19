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

## Security & Performance (New)
| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$allowedShellCommands` | array | `[]` | Whitelist of allowed shell commands for `ShellJob`. Empty array = allow all (backward compatible). Non-empty = only listed commands permitted. |
| `$queueRateLimits` | array | `[]` | Per-queue rate limits (jobs/minute). Format: `['queue_name' => max_per_minute]`. 0 or missing = no limit. Uses cache-based token bucket algorithm. |
| `$deadLetterQueue` | ?string | `null` | Queue name for permanently failed jobs. When set, jobs exceeding max retries are moved here with metadata (reason, timestamp, attempts, original_queue). |
| `$jobTimeout` | int | `300` | Maximum execution time per job in seconds. 0 = disabled. Uses `pcntl_alarm` if available, falls back to time check. |
| `$batchSize` | int | `1` | Reserved for future batch processing feature. Currently unused. |

## Retry / Backoff
| Property | Type | Notes |
|----------|------|-------|
| `$retryBackoffStrategy` | string | `none`, `fixed`, `exponential`. |
| `$retryBackoffBase` | int | Base delay (seconds) for first retry. |
| `$retryBackoffMultiplier` | float | Exponential factor `delay=base*multiplier^(attempt-1)` |
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

### Enhanced Token Pattern Detection
In addition to key-based masking, the logger automatically detects and masks:
- **JWT Tokens**: Format `xxx.yyy.zzz` → `***JWT_TOKEN***`
- **API Keys**: Alphanumeric strings ≥32 characters → `***API_KEY***`
- **Bearer Tokens**: `Bearer <token>` → `Bearer ***TOKEN***`

This pattern-based detection works independently of key names, providing defense-in-depth for leaked credentials.

## Security Features

### Shell Command Whitelisting
Restrict which shell commands can be executed by `ShellJob`:
```php
public array $allowedShellCommands = ['ls', 'grep', 'cat', 'find'];
```
- Empty array (default): All commands allowed (backward compatible)
- Non-empty: Only listed commands permitted
- Throws `JobException::forShellCommandNotAllowed()` on violation

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
Automatic routing of failed jobs for forensic analysis:
```php
public ?string $deadLetterQueue = 'failed_jobs';
```
Behavior:
- Jobs exceeding max retries are moved to DLQ
- Metadata added: `dlq_reason`, `dlq_timestamp`, `dlq_attempts`, `original_queue`
- Retrieve stats: `$dlq->getStats()` (total count, per-queue breakdown)
- Disabled when `null` (default)

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
