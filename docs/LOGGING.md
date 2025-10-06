# Logging

The package provides structured execution logs capturing detailed metadata for each job run.

## Drivers
- File: Each job name gets its own JSON lines file (one array stored per file or line-based depending on handler implementation).
- Database: Each execution stored as a row in the jobs log table via `JobsLogModel`.

Configured via `Jobs::$log` (`file` or `database`).

## Data Fields
| Field | Description |
|-------|-------------|
| executionId | UUID v4 unique per run scope (start -> end). |
| name | Logical job name (from `named()`). |
| job | Handler key (e.g. `command`, `shell`). |
| attempt | Attempts count at completion of the run. |
| queue | Queue name (if queued). |
| source | Origin (e.g. `queue`, `cron`) when set. |
| retryStrategy | Current configured strategy string. |
| payload | Masked JSON payload snapshot. |
| payloadHash | SHA-256 of payload JSON (null if empty). |
| start_at / end_at | Timestamps of run boundaries. |
| duration | HH:MM:SS formatted diff. |
| output | Masked/truncated output (success only). |
| error | Masked/truncated error (failure only). |
| outputLength | Raw character length of (truncated) output. |
| test_time | Optional injected test timestamp. |
| environment | Reserved for future environment tagging. |
| status (DB only) | OK or ERROR derived from presence of `error`. |
| data (DB only) | Full JSON dump for forward compatibility. |

## Sensitive Data Masking
Sensitive keys are the union of defaults (`password`, `token`, `secret`, `authorization`, `api_key`) plus user configured keys. Recursively replaced with `***`.

## Truncation
If `maxOutputLength` is set, output & error strings longer than the limit are truncated with a suffix marker.

## Pruning
`maxLogsPerJob` enforces a rolling window. Database handler deletes oldest rows beyond the limit. File handler may either append indefinitely or implement pruning (future enhancement).

## Example (File JSON Entry)
```json
{
  "executionId": "b7c4...",
  "name": "nightly_runner",
  "job": "command",
  "attempt": 1,
  "queue": "default",
  "source": "queue",
  "retryStrategy": "exponential",
  "payload": "{\"task\":\"rotate\"}",
  "payloadHash": "a9d...",
  "start_at": "2025-10-06 12:30:00",
  "end_at": "2025-10-06 12:30:02",
  "duration": "00:00:02",
  "output": null,
  "error": null,
  "outputLength": 0
}
```

## Usage
```php
$logger = new JobLogger();
$logger->start();
// ... run job ...
$result = new ExecutionResult(true, 'All good', null, $startedAt, microtime(true));
$logger->end();
$logger->log($job, $result);
```

## Accessing History
- File: read the job-specific JSON file under `filePath`.
- Database: use `DatabaseHandler::history($name, $limit)` or query via model.

## Extended Fields
Additional fields (payloadHash, outputLength, retryStrategy) support observability and integrity checks.

## Error Masking Example
Error messages themselves pass through masking filter—embedded sensitive key-value substrings are redacted when keys are explicit.

## Architecture Overview
The logging pipeline is intentionally thin:

1. `JobLogger` orchestrates timing (`start()` / `end()`), shapes the structured array and applies masking & truncation.
2. The selected handler (file or database) receives a single JSON string (level + message) via `handle()`.
3. Handlers persist the record (append to file, insert row) and may enforce pruning.

Handlers are stateless aside from optional per-run context (e.g. `setPath($name)`). Retry / attempt logic is entirely external and only the final attempt value is logged.

### Sequence
```
JobLogger::start()
  -> execute job logic
JobLogger::end()
JobLogger::log($job, $executionResult)
  -> ensure handler
  -> build normalized data structure
  -> mask sensitive fields
  -> JSON encode and delegate to handler->handle('info', $json)
```

## Implementing a Custom Logger Handler
You can add new persistence or forwarding targets (e.g. Elasticsearch, stdout, HTTP webhook, syslog) by creating a handler compatible with CodeIgniter log handler conventions.

### Minimal Custom Handler Example
```php
namespace App\Logging;

use CodeIgniter\Log\Handlers\BaseHandler;

class StdoutHandler extends BaseHandler
{
    private ?string $name = null;

    public function handle($level, $message): bool
    {
        // $message is a JSON string produced by JobLogger
        fwrite(STDOUT, '[' . strtoupper($level) . '] ' . $message . PHP_EOL);
        return true;
    }

    // Optional: allow JobLogger to set a logical name
    public function setPath(string $name): self
    {
        $this->name = $name;
        return $this;
    }
}
```

### Registering the Handler
Add it to the config map (`Jobs::$loggers`) and select it:
```php
$cfg = config('Jobs');
$cfg->loggers['stdout'] = \App\Logging\StdoutHandler::class;
$cfg->log = 'stdout';
```

### Handler Responsibilities
- Accept the JSON message from `JobLogger` unchanged (do not mutate structure).
- Perform fast, non-blocking write if used in high-throughput queues (consider async/buffer for remote sinks).
- Return `true` even on benign failures to avoid throwing inside critical path (or throw intentionally for strict mode).

### Pruning Strategy
If your backend accumulates data (e.g. a custom database / index), implement a similar pruning routine:
1. Count existing records for a job name.
2. If >= `maxLogsPerJob` delete oldest `(count - max + 1)`.

### Masking & Security
Do NOT re-mask inside handler. Payload is already sanitized. If you need additional filtering (e.g. GDPR redaction), clone and edit the decoded object, not the original string (keep original for audit if allowed).

### Adding Derived Fields
If you want custom derived metadata (e.g. latency buckets) prefer doing it upstream (extend `JobLogger`) to keep handlers focused. Alternatively, decode `$message`, append keys and re-encode—understanding this may break forward compatibility if schema evolves.

## Extending JobLogger Itself
To inject extra global tags (environment, build info):
```php
class TaggedJobLogger extends \Daycry\Jobs\Loggers\JobLogger {
    protected function additionalContext(): array { return ['build' => 'abc123']; }
}
```
You would then override the `log()` method to merge `additionalContext()` into the `$data` array before encoding.

## Testing Custom Handlers
1. Create a job that emits known output.
2. Use the handler in a test environment (`$cfg->log = 'stdout'`).
3. Capture output or mock resources (e.g. HTTP client) asserting JSON schema (presence of required fields) and masking (`***` for sensitive keys).

## Field Stability & Versioning
While core fields are stable, you should design handlers to ignore unknown future keys (treat as forward-compatible). When storing JSON in a schemaless sink, keep original raw message for audit.

## Troubleshooting
| Symptom | Cause | Fix |
|---------|-------|-----|
| Empty file | `logPerformance` disabled | Enable in config. |
| Unmasked secrets | Custom handler modified JSON or masking keys absent | Ensure handler is pass-through; extend `$sensitiveKeys`. |
| Duplicate entries | Multiple logger instances per run | Reuse single `JobLogger` per execution path. |
| Pruning not working | Custom handler missing deletion logic | Implement count + delete oldest records. |

---
For questions or enhancements, open an issue and describe your handler use-case.
