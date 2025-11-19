# Advanced Features

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
