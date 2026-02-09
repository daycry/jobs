# Exception Handling

This document explains how exceptions are processed and handled throughout the Jobs system lifecycle.

## Overview

The Jobs system implements a **resilient exception handling** strategy with multiple layers:

1. **Safe Execution Wrapper** (`JobLifecycleCoordinator`)
2. **Retry Logic** (`JobLifecycleCoordinator`)
3. **Logging & Metrics** (automatic on exceptions)
4. **Dead Letter Queue** (permanent failures)
5. **Notifications** (success/failure alerts)

**Key Principle**: Exceptions **never crash the worker**. All errors are caught, logged, and handled gracefully with automatic retries.

---

## Exception Flow Diagram

```
Job Execution Attempt
        ↓
┌─────────────────────┐
│JobLifecycleCoordinator│
│  - executeJobInternal(job) │
└─────────────────────┘
        ↓
   Try-Catch Block
        ↓
   ┌─────────┐
   │Exception│ 
   │Thrown?  │
   └─────────┘
    ↙      ↘
  YES       NO
   ↓         ↓
Catch    Return
Block    Success
   ↓         ↓
Create   ExecutionResult
ExecutionResult (success=true)
(success=false)
   ↓         ↓
   └─────┬───┘
         ↓
JobLifecycleCoordinator
    - Check maxRetries
    - Send notifications
         ↓
    ┌─────────┐
    │Retries  │
    │Left?    │
    └─────────┘
     ↙      ↘
   YES       NO
    ↓         ↓
 Requeue   Dead Letter Queue
 + Backoff   (if configured)
    ↓         ↓
Log Metrics  Log Metrics
jobs_requeued jobs_failed_permanently
```

---

## Layer 1: Safe Execution (Internal)

**Location**: [`src/Execution/JobLifecycleCoordinator.php`](c:\\laragon\\github\\jobs\\src\\Execution\\JobLifecycleCoordinator.php)

**Responsibility**: Execute the job handler with exception safety, handling buffer capture and timing.

### Code Flow

```php
private function executeJobInternal(Job $job): ExecutionResult
{
    $start = microtime(true);
    
    try {
        // 1. Resolve handler class
        $class = $mapping[$job->getJob()] ?? null;
        if (!$class || !is_subclass_of($class, Job::class)) {
            throw JobException::forInvalidJob($job->getJob());
        }
        
        // 2. Execute lifecycle hooks
        $handler = new $class();
        $job = $handler->beforeRun($job);
        
        // 3. Capture output buffer
        ob_start();
        $returned = $handler->handle($job->getPayload());
        $buffer = ob_get_clean();
        
        // 4. Post-execution hook
        $job = $handler->afterRun($job);
        
        // 5. Success result
        return new ExecutionResult(
            success: true,
            output: $this->normalizeOutput($returned),
            error: null,
            startedAt: $start,
            endedAt: microtime(true)
        );
        
    } catch (Throwable $e) {
        // 6. Clean output buffer on exception
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // 7. Failure result with exception message
        return new ExecutionResult(
            success: false,
            output: null,
            error: $e->getMessage(),
            startedAt: $start,
            endedAt: microtime(true)
        );
    }
}
```

### What Gets Caught

| Exception Source | Caught By | Result |
|-----------------|-----------|--------|
| Invalid job handler | `forInvalidJob()` check | `ExecutionResult(success=false)` |
| `beforeRun()` exception | Main try-catch | Error message captured |
| `handle()` exception | Main try-catch | Error message captured |
| `afterRun()` exception | Main try-catch | Error message captured |
| Any `Throwable` | Main try-catch | Error message captured |

### Example Scenarios

**Scenario 1: Handler throws exception**
```php
// In your custom job handler
public function handle($payload)
{
    throw new RuntimeException('Database connection failed');
}
```

**Result**:
```php
ExecutionResult(
    success: false,
    output: null,
    error: 'Database connection failed',
    startedAt: 1737339240.123,
    endedAt: 1737339240.456
)
```

**Scenario 2: Invalid payload causes error**
```php
public function handle($payload)
{
    $data = json_decode($payload, true);
    
    // If payload is invalid JSON, json_decode returns null
    // Accessing null array throws TypeError
    return $data['user_id']; 
}
```

**Result**:
```php
ExecutionResult(
    success: false,
    error: 'Trying to access array offset on null',
    ...
)
```

---

## Layer 2: JobLifecycleCoordinator (Retry Logic)

**Location**: [`src/Execution/JobLifecycleCoordinator.php`](c:\\laragon\\github\\jobs\\src\\Execution\\JobLifecycleCoordinator.php)

**Responsibility**: Orchestrate retries, timeouts, and notifications.

### Code Flow

```php
public function run(Job $job, string $source = 'cron'): LifecycleOutcome
{
    $maxRetries = $job->getMaxRetries() ?? 0;
    $attemptNumber = $job->getAttempt();
    
    while (true) {
        $attemptNumber++;
        
        // Execute with timeout protection (if configured)
        $result = $this->safeExecuteWithTimeout($job, $timeout);
        
        // Track attempt metadata
        $attemptsMeta[] = [
            'attempt' => $attemptNumber,
            'success' => $result->success,
            'error' => $result->error,
            'duration' => $result->durationSeconds()
        ];
        
        // Send notifications
        if ($result->success && $job->shouldNotifyOnSuccess()) {
            $job->notify($result);
        } elseif (!$result->success && $job->shouldNotifyOnFailure()) {
            $job->notify($result);
        }
        
        // SUCCESS: Exit retry loop
        if ($result->success) {
            break;
        }
        
        // FAILURE: Check if retries exhausted
        if ($attemptNumber > $maxRetries) {
            $finalFailure = true;
            break;
        }
        
        // RETRY: Calculate backoff delay
        $delay = $policy->computeDelay($attemptNumber + 1);
        sleep($delay);
    }
    
    // Return final outcome
    return new LifecycleOutcome(
        finalResult: $result,
        attempts: $attemptNumber,
        finalFailure: $finalFailure,
        attemptsMeta: $attemptsMeta
    );
}
```

### Retry Policy

Configured via `Config\Jobs`:

```php
public string $retryBackoffStrategy = 'exponential';
public int $retryBackoffBase = 60;        // 60 seconds
public float $retryBackoffMultiplier = 2.0;
public int $retryBackoffMax = 3600;       // 1 hour
public bool $retryBackoffJitter = true;
```

**Delay Calculation Examples**:

| Strategy | Attempt | Formula | Delay |
|----------|---------|---------|-------|
| `none` | Any | 0 | 0s (immediate) |
| `fixed` | Any | base | 60s |
| `exponential` | 1 | base × multiplier^0 | 60s |
| `exponential` | 2 | base × multiplier^1 | 120s |
| `exponential` | 3 | base × multiplier^2 | 240s |
| `exponential` | 4 | base × multiplier^3 | 480s |

With `jitter=true`: ±15% random variation (prevents thundering herd).

### Per-Job Retry Configuration

```php
// Set max retries per job
$job = (new Job('command', 'import:users'))
    ->maxRetries(5);  // Try up to 6 times total (1 initial + 5 retries)

// Disable retries for specific job
$job->maxRetries(0); // Fail immediately on error
```

---

## Layer 3: RequeueHelper (Finalization)

**Location**: [`src/Queues/RequeueHelper.php`](c:\\laragon\\github\\jobs\\src\\Queues\\RequeueHelper.php)

**Responsibility**: Increment attempts, log metrics, route to DLQ.

### Code Flow

```php
public function finalize(Job $job, JobEnvelope $envelope, callable $removeFn, bool $success): void
{
    // Increment attempt counter (authoritative)
    $job->addAttempt();
    
    if ($success) {
        $removeFn($job, false); // Remove from queue
        $this->metrics->increment('jobs_succeeded');
        return;
    }
    
    // FAILURE
    $maxRetries = $job->getMaxRetries();
    $currentAttempt = $job->getAttempt();
    
    // Determine if should requeue
    $shouldRequeue = ($maxRetries !== null) 
        && ($currentAttempt < ($maxRetries + 1));
    
    if ($shouldRequeue) {
        // REQUEUE for retry
        $removeFn($job, true); 
        $this->metrics->increment('jobs_failed');
        $this->metrics->increment('jobs_requeued');
    } else {
        // PERMANENT FAILURE
        $removeFn($job, false);
        $this->metrics->increment('jobs_failed');
        $this->metrics->increment('jobs_failed_permanently');
        
        // Move to Dead Letter Queue
        $this->dlq->store(
            $job,
            'Max retries exceeded',
            $currentAttempt
        );
    }
}
```

### Metrics Emitted

| Metric | When | Labels |
|--------|------|--------|
| `jobs_succeeded` | Job completes successfully | `queue` |
| `jobs_failed` | Job attempt fails | `queue` |
| `jobs_requeued` | Failed job retried | `queue` |
| `jobs_failed_permanently` | Retries exhausted | `queue` |

---

## Layer 4: Dead Letter Queue

**Location**: [`src/Libraries/DeadLetterQueue.php`](c:\\laragon\\github\\jobs\\src\\Libraries\\DeadLetterQueue.php)

**Purpose**: Store permanently failed jobs for forensic analysis.

### Automatic Storage

When a job exceeds `maxRetries`, it's automatically moved to the configured DLQ:

```php
// Config
public ?string $deadLetterQueue = 'failed_jobs';
```

### Metadata Added

```php
$envelope->payload['meta'] = [
    'dlq_reason' => 'Max retries exceeded',
    'dlq_timestamp' => '2026-01-19T15:30:00Z',
    'dlq_attempts' => 5,
    'original_queue' => 'high_priority',
    'original_error' => 'Database connection timeout'
];
```

### Querying DLQ

```php
use Daycry\Jobs\Libraries\DeadLetterQueue;

$dlq = new DeadLetterQueue();
$stats = $dlq->getStats();

/*
[
    'total' => 42,
    'by_queue' => [
        'default' => 30,
        'high_priority' => 12
    ]
]
*/
```

---

## Layer 5: Logging

**Location**: [`src/Loggers/JobLogger.php`](c:\\laragon\\github\\jobs\\src\\Loggers\\JobLogger.php)

**Automatic on Every Execution** (if `logPerformance = true`):

### Log Structure

```json
{
  "execution_id": "abc123",
  "job_name": "import_users",
  "queue": "default",
  "source": "queue",
  "attempt": 3,
  "payload_hash": "sha256:...",
  "output": "Processed 1500 records",
  "error": "Database connection timeout",
  "output_length": 23,
  "started_at": "2026-01-19 15:30:00",
  "ended_at": "2026-01-19 15:30:05",
  "duration_seconds": 5.23,
  "retry_strategy": "exponential"
}
```

### Exception Information Captured

- **Error Message**: `$exception->getMessage()`
- **Attempt Number**: Which retry attempt failed
- **Duration**: How long before exception thrown
- **Payload Hash**: For correlation with previous attempts

### Sensitive Data Protection

Exception messages are **automatically sanitized**:

```php
// Before sanitization
error: "API key sk_live_51H9X2sK3Zq8... is invalid"

// After sanitization  
error: "API key ***API_KEY*** is invalid"
```

Patterns detected:
- JWT tokens → `***JWT_TOKEN***`
- API keys → `***API_KEY***`
- Bearer tokens → `Bearer ***TOKEN***`

---

## Layer 6: Notifications

**Configuration**:

```php
$job->notifyOnSuccess();   // Email on success
$job->notifyOnFailure();   // Email on failure
$job->notifyOnCompletion(); // Email always
```

### Notification on Exception

When an exception occurs and `notifyOnFailure()` is enabled:

```php
// In JobLifecycleCoordinator
if (!$result->success && $job->shouldNotifyOnFailure()) {
    $job->notify($result);
}
```

**Email Content**:
- Job name
- Error message (sanitized)
- Attempt number
- Duration
- Timestamp

---

## Complete Exception Lifecycle Example

### Setup

```php
// Job configuration
$job = (new Job('command', 'process:payment'))
    ->named('payment_processor')
    ->maxRetries(3)
    ->timeout(300)
    ->notifyOnFailure()
    ->enqueue('high_priority');
```

### Execution Timeline

**Attempt 1** (15:30:00):
```
1. JobExecutor::execute() called
2. Payment API throws SocketException: "Connection refused"
3. Exception caught in try-catch
4. Returns ExecutionResult(success=false, error="Connection refused")
5. JobLifecycleCoordinator checks: attempt 1 <= maxRetries 3
6. Calculates delay: 60s (exponential backoff)
7. Sleeps 60 seconds
```

**Attempt 2** (15:31:00):
```
1. JobExecutor::execute() called again
2. Payment API throws TimeoutException: "Request timeout after 30s"
3. Exception caught
4. Returns ExecutionResult(success=false, error="Request timeout after 30s")
5. Coordinator checks: attempt 2 <= maxRetries 3
6. Calculates delay: 120s
7. Sleeps 120 seconds
```

**Attempt 3** (15:33:00):
```
1. JobExecutor::execute() called
2. Payment API throws AuthException: "Invalid API key"
3. Exception caught
4. Returns ExecutionResult(success=false, error="Invalid API key")
5. Coordinator checks: attempt 3 <= maxRetries 3
6. Calculates delay: 240s
7. Sleeps 240 seconds
```

**Attempt 4** (15:37:00):
```
1. JobExecutor::execute() called
2. Payment API throws ServerError: "Internal server error"
3. Exception caught
4. Returns ExecutionResult(success=false, error="Internal server error")
5. Coordinator checks: attempt 4 > maxRetries 3
6. Sets finalFailure = true
7. Sends failure notification email
8. Returns LifecycleOutcome(finalFailure=true)
```

**Finalization**:
```
1. RequeueHelper::finalize() called with success=false
2. Checks: shouldRequeue = false (retries exhausted)
3. Calls $removeFn($job, false) - remove from queue
4. Emits metrics:
   - jobs_failed{queue=high_priority} +1
   - jobs_failed_permanently{queue=high_priority} +1
5. Stores in Dead Letter Queue:
   {
     dlq_reason: "Max retries exceeded",
     dlq_attempts: 4,
     original_queue: "high_priority",
     original_error: "Internal server error"
   }
```

**Logged Records** (4 entries):
```json
[
  {"attempt": 1, "error": "Connection refused", "duration": 0.5},
  {"attempt": 2, "error": "Request timeout after 30s", "duration": 30.2},
  {"attempt": 3, "error": "Invalid API key", "duration": 0.3},
  {"attempt": 4, "error": "Internal server error", "duration": 1.2}
]
```

---

## Exception Types & Handling

### 1. JobException (System Exceptions)

**Thrown for configuration/validation errors:**

```php
JobException::forInvalidJob('unknown_handler');
JobException::forShellCommandNotAllowed('rm -rf /');
JobException::forJobTimeout('long_task', 300);
JobException::forRateLimitExceeded('high_priority', 100);
```

**Handling**: Treated as permanent failures (no retry by default).

### 2. RuntimeException (Job Logic Errors)

```php
throw new RuntimeException('User not found: ID 12345');
```

**Handling**: Retried according to `maxRetries` configuration.

### 3. Throwable (All Errors)

Catches **everything**:
- `Exception`
- `Error`
- `TypeError`
- `ValueError`
- `DivisionByZeroError`
- Custom exceptions

```php
// Even fatal errors are caught
function handle($payload) {
    return 1 / 0; // DivisionByZeroError
}
```

**Handling**: All caught and converted to `ExecutionResult(success=false)`.

---

## Best Practices

### 1. Throw Meaningful Exceptions

```php
// ❌ BAD
throw new Exception('Error');

// ✅ GOOD
throw new RuntimeException('Failed to import user ID 12345: Email validation failed');
```

### 2. Use Specific Exception Types

```php
// For validation errors
throw new InvalidArgumentException('Payload missing required field: user_id');

// For external service failures
throw new RuntimeException('Stripe API returned 503 Service Unavailable');

// For business logic violations
throw new DomainException('Cannot process payment: insufficient balance');
```

### 3. Clean Up Resources

```php
public function handle($payload)
{
    $file = fopen('temp.csv', 'w');
    
    try {
        // Process data
        fwrite($file, $data);
        return 'Success';
    } finally {
        // Always close file, even on exception
        fclose($file);
    }
}
```

### 4. Configure Appropriate Retries

```php
// Quick operations: few retries
$emailJob = (new Job('command', 'send:email'))->maxRetries(2);

// External API calls: more retries
$apiJob = (new Job('command', 'sync:stripe'))->maxRetries(5);

// Critical operations: no retries (fail fast)
$paymentJob = (new Job('command', 'charge:card'))->maxRetries(0);
```

### 5. Use Callbacks for Error Handling

```php
$mainJob = (new Job('command', 'import:data'))
    ->maxRetries(3)
    ->catch(
        (new Job('command', 'send:error:alert'))
            ->enqueue('notifications')
    );
```

---

## Troubleshooting

### Exception Not Logged

**Symptom**: Exception thrown but no log entry.

**Causes**:
- `logPerformance = false` in config
- Log handler misconfigured
- Exception thrown **before** JobExecutor (e.g., in queue worker loop)

**Solution**: Verify `Config\Jobs::$logPerformance = true`.

### Job Retries Infinitely

**Symptom**: Job keeps retrying forever.

**Causes**:
- `maxRetries` not set (defaults to 0, but requeue logic might differ)
- Database backend not persisting attempt count

**Solution**: Explicitly set `maxRetries`:
```php
$job->maxRetries(3);
```

### Exception Message Truncated

**Symptom**: Error message cut off in logs.

**Cause**: `maxOutputLength` config limit.

**Solution**: Increase limit or set `null`:
```php
public ?int $maxOutputLength = null; // Unlimited
```

### Worker Crashes on Exception

**Symptom**: Queue worker stops processing.

**Causes**:
- Exception thrown **outside** job execution (worker loop itself)
- Out of memory error (not caught)
- SIGTERM/SIGKILL signal

**Solution**: Use process supervisor (systemd, supervisord):
```ini
[program:jobs-worker]
command=php spark jobs:queue:run
autorestart=true
stderr_logfile=/var/log/jobs-worker.err.log
```

---

## Monitoring Exception Rates

### Using Metrics

```php
// Get failure rate
$failed = $metrics->getValue('jobs_failed');
$succeeded = $metrics->getValue('jobs_succeeded');
$failureRate = ($failed / ($failed + $succeeded)) * 100;
```

### Using Health Check

```bash
php spark jobs:health --json | jq '.queues.default.last_24h.failure_rate'
# Output: 7.2
```

### Alerting Rules (Prometheus)

```yaml
groups:
  - name: jobs
    rules:
      - alert: HighJobFailureRate
        expr: |
          (
            rate(jobs_failed[5m]) / 
            rate(jobs_succeeded[5m] + jobs_failed[5m])
          ) > 0.1
        for: 10m
        annotations:
          summary: "Job failure rate above 10% for 10 minutes"
```

---

## Summary

**Exception Handling Guarantees**:

1. ✅ **No Crashes**: All exceptions caught at execution layer
2. ✅ **Automatic Retries**: Configurable retry policy with backoff
3. ✅ **Audit Trail**: Every exception logged with full context
4. ✅ **Metrics**: Failure rates tracked in real-time
5. ✅ **Notifications**: Email alerts on failures
6. ✅ **Forensics**: Dead Letter Queue preserves failed jobs
7. ✅ **Security**: Exception messages sanitized before logging
8. ✅ **Observability**: Health checks expose failure statistics

**For Developers**:
- Throw exceptions freely in job handlers
- System handles retries automatically
- Focus on business logic, not error handling boilerplate

**For Operators**:
- Monitor via `jobs:health` command
- Review DLQ for systematic issues
- Adjust retry policies based on failure patterns
