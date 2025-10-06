# Configuration

The package reads its settings from `Daycry\Jobs\Config\Jobs` (you may publish / copy to your app namespace to customize). Below is an overview of the most relevant options.

## Core
| Property | Type | Description |
|----------|------|-------------|
| `$jobs` | array | Mapping of job handler keys (e.g. `command`, `shell`) to concrete job classes. |
| `$queues` | string|array | Comma list or array of queue names available. First is used as default if none chosen. |
| `$worker` | string | Active queue backend key referencing `$workers`. |
| `$workers` | array | Map of worker key => queue implementation class. |
| `$logPerformance` | bool | Enable/disable structured execution logging. |
| `$log` | string | Logging driver key (`file` or `database`). |
| `$loggers` | array | Map logging driver key => handler class. |
| `$filePath` | string | Directory for file logs (one JSON file per job name). |
| `$maxLogsPerJob` | int | Prune old records (database) or lines (file) beyond this count (file handler may ignore if not implemented). |
| `$sensitiveKeys` | array | List of payload/output/error keys (case-insensitive) to mask recursively. |
| `$maxOutputLength` | ?int | Truncate output & error string length (null = unlimited). |

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
