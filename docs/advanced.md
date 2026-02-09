# Advanced Features

## Accessing Job Context
You can access the current Job instance (ID, attempts, configuration) from within your handler by using the `$this->currentJob` property. This is available in all standard Job types (`CommandJob`, `ShellJob`, `UrlJob`, `ClosureJob`, `EventJob`).

### Example
```php
// In a custom CommandJob or ShellJob
public function handle(mixed $payload): mixed
{
    $jobId = $this->currentJob->getJobId();
    $attempts = $this->currentJob->getAttempt();
    
    // Pass context to the command
    return command("process:data --id={$jobId} --try={$attempts}");
}
```

If you are creating a custom Job class, simply use the trait:
```php
use Daycry\Jobs\Traits\InteractsWithCurrentJob;

class MyCustomJob extends Job implements JobInterface
{
    use InteractsWithCurrentJob;

    public function handle(mixed $payload): mixed
    {
        // Access $this->currentJob
    }
}
```

## Direct Queueing
Enqueue ad-hoc jobs without scheduler:
```php
$id = Services::queueJob('command','jobs:cleanup','default', function($j){
    $j->named('cleanup_run')->maxRetries(3)->timeout(120);
});
```

## Queue Management

### Using QueueManager
Access queue backends centrally:
```php
use Daycry\Jobs\Libraries\QueueManager;

// Get default queue
$queue = QueueManager::instance()->getDefault();

// Get specific backend
$redis = QueueManager::instance()->get('redis');
$database = QueueManager::instance()->get('database');

// Enqueue directly
$job = new Job(job: 'command', payload: 'jobs:cleanup');
$job->setQueue('high-priority');
$id = $redis->enqueue($job->getDataQueue());
```

### Instrumenting Queues with Metrics
```php
use Daycry\Jobs\Libraries\InstrumentedQueueDecorator;
use Daycry\Jobs\Metrics\Metrics;

$queue = QueueManager::instance()->get('redis');
$instrumented = new InstrumentedQueueDecorator(
    queue: $queue,
    metrics: Metrics::get(),
    backendName: 'redis'
);

// All operations now tracked:
// - queue_enqueue_total{backend,queue,status}
// - queue_fetch_total{backend,queue}
// - queue_ack_total / queue_nack_total
// - queue_enqueue_duration_seconds / queue_fetch_duration_seconds
```

## Fluent Job Chaining (Enhanced)

The callback API now supports a fluent chaining syntax for complex workflows:

### Basic Chaining
```php
use Daycry\Jobs\Job;

$processPayment = new Job('command', 'process:payment');
$sendInvoice = new Job('command', 'send:invoice');
$notifyAdmin = new Job('command', 'notify:admin');
$cleanup = new Job('closure', fn() => log_message('info', 'Workflow complete'));

$processPayment
    ->then($sendInvoice)        // Execute on success
    ->catch($notifyAdmin)       // Execute on failure
    ->finally($cleanup)         // Always execute
    ->push();
```

### Chain Multiple Jobs
```php
$job->chain([
    new Job('command', 'step1'),
    new Job('command', 'step2'),
    new Job('command', 'step3'),
])->push();
```

### Method Reference
| Method | Filter | Description |
|--------|--------|-------------|
| `then(Job $next)` | `success` | Enqueue after successful completion |
| `catch(Job $handler)` | `failure` | Enqueue on failure/error |
| `finally(Job $cleanup)` | `always` | Always enqueue regardless of outcome |
| `chain(array $jobs)` | Sequential | Execute jobs in order, stopping on failure |

### Advanced Example with Inheritance
```php
$importJob = (new Job('command', 'import:users'))
    ->named('user_import')
    ->enqueue('default')
    ->then(
        (new Job('command', 'send:welcome:emails'))
            ->enqueue('notifications')
    )
    ->catch(
        (new Job('closure', function($meta) {
            // Access parent error via $meta['parentError']
            log_message('error', 'Import failed: ' . $meta['parentError']);
        }))
    )->finally(
        (new Job('command', 'cleanup:temp:files'))
    );

$importJob->push();
```

**Inherited Fields** (`inherit` option in `setCallbackJob`):
- `output` → `parentOutput`
- `error` → `parentError`
- `attempts` → `parentAttempts`
- `name` → `parentName`
- `source` → `parentSource`

## Callbacks & Chaining
Attach a callback job run after completion:
```php
$job->setCallbackJob(function($parent){
    return (new Job(job: 'command', payload: 'jobs:notify:done'))->enqueue('notifications');
}, ['on' => 'success']);
```

## Retry Policies
Configure backoff in config (`retryBackoffStrategy`, `base`, `multiplier`, `jitter`).

## Sensitive Data Masking
Add keys to `sensitiveKeys` to mask in logs recursively.

## Metrics
Provide a custom collector implementing `MetricsCollectorInterface` and set `Jobs::$metricsCollector`.

Next: [Metrics & Monitoring](metrics-monitoring.md)
