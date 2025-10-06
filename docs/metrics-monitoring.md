# Metrics & Monitoring

## Core Counters
- `jobs_succeeded`
- `jobs_failed`
- `jobs_requeued`
- `jobs_fetched`
- `jobs_exec_seconds` (histogram-like summary)
- `jobs_age_seconds`

## Enabling
```php
public ?string $metricsCollector = \App\Metrics\PrometheusCollector::class; // in Jobs config
```
Set to `null` to disable.

## Custom Metric Example
```php
$metrics = \Daycry\Jobs\Metrics\Metrics::get();
$metrics?->increment('import_batches_total', 1, ['job' => 'jobs:import']);
```

## Exporting
- Prometheus: expose a `/metrics` endpoint using your collector's registry.
- InMemory: debug via `$metrics->getSnapshot()`.

## Dashboards
Use Grafana / Kibana for visualization, correlating retries vs failures, queue latency, etc.
