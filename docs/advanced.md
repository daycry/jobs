# Advanced Features

## Direct Queueing
Enqueue ad-hoc jobs without scheduler:
```php
$id = Services::queueJob('command','jobs:cleanup','default', function($j){
    $j->named('cleanup_run')->maxRetries(3)->timeout(120);
});
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
