# Metrics

This package includes a **pluggable metrics layer** so you can export operational data
about job executions (success/failure counts, retries, timing, latency, business KPIs)
to any monitoring system (Prometheus, StatsD, Influx, OpenTelemetry, etc.).

---
## Contents
1. Core Concepts
2. Built‑in Interface & Default Implementation
3. What Is Instrumented Out‑Of‑The‑Box
4. Enabling / Injecting a Collector
5. Adding Custom Metrics (Examples)
6. Implementing Your Own Collector (Prometheus Example)
7. Recommended Naming & Label Conventions
8. Extension Points & Ideas
9. Troubleshooting

---
## 1. Core Concepts

Instrumentation is intentionally minimal and **push‑style**: the code paths that care
about a metric call a simple collector with:

```php
$metrics->increment('jobs_succeeded', 1, ['queue' => 'default']);
$metrics->observe('job_duration_seconds', 0.352, ['queue' => 'high', 'job' => 'jobs:import']);
```

Your implementation decides how to aggregate, store, export or flush these values.

---
## 2. Built‑in Interface & Default Implementation

Interface: `Daycry\Jobs\Metrics\MetricsCollectorInterface`

```php
interface MetricsCollectorInterface
{
    public function increment(string $counter, int $value = 1, array $labels = []): void;
    public function observe(string $metric, float $value, array $labels = []): void; // histograms / summaries
    public function getSnapshot(): array; // debugging / tests
}
```

Reference implementation: `InMemoryMetricsCollector` (keeps counters and simple histogram
aggregations in PHP arrays – good for local dev & tests, not production grade).

Histograms store: `count`, `sum`, `min`, `max` per unique (name+labels) key.

---
## 3. What Is Instrumented Out‑Of‑The‑Box

Currently the core increments the following counters in `RequeueHelper`:

| Counter Name       | When Incremented                                | Labels           |
|--------------------|--------------------------------------------------|------------------|
| `jobs_succeeded`   | A job finishes successfully                      | `queue`          |
| `jobs_failed`      | A job attempt fails (final attempt included)     | `queue`          |
| `jobs_requeued`    | A job fails and is placed back on the queue      | `queue`          |
| `jobs_timed_out`   | (Reserved – implement when you add timeout hook) | `queue` (suggest) |

Additional custom examples (duration, latency, attempts) are trivial to add – see below.

---
## 4. Enabling / Injecting a Collector

`RequeueHelper` accepts an optional `MetricsCollectorInterface` in its constructor.
The recommended (current) way to enable metrics is through the configuration property
`Jobs::$metricsCollector` (see 4.1). The queue worker command (`jobs:queue:run`) will
instantiate that class automatically (zero‑argument constructor) and provide it to the
internals. If you set it to `null`, all metric calls become no‑ops via the nullsafe operator.

If you still need to wire a collector manually (e.g. inside a custom script), you can do:
```php
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Queues\RequeueHelper;

$collector = new InMemoryMetricsCollector();
$requeue   = new RequeueHelper($collector); // pass this where finalize() is invoked
```

### 4.0 Facade Helper
In most internal code you can now simply call:
```php
use Daycry\Jobs\Metrics\Metrics;

$metrics = Metrics::get(); // null if disabled
$metrics?->increment('jobs_custom_metric', 1, ['queue' => 'default']);
```
This resolves the configured collector once (singleton) and reuses it.

### 4.1 Configuration Shortcut
You can set a global collector class in `config('Jobs')->metricsCollector`:

```php
// app/Config/Jobs.php (extends Daycry\Jobs\Config\Jobs)
public ?string $metricsCollector = \App\Metrics\PrometheusCollector::class; // Must implement MetricsCollectorInterface
```

The `jobs:queue:run` command will automatically instantiate this class (no arguments
constructor) and fall back to the in‑memory collector if misconfigured. Set it to `null`
to disable metrics entirely.

### 4.2 Disabling Metrics
Set the property to `null`:
```php
public ?string $metricsCollector = null; // all increment/observe are skipped
```
This avoids any runtime overhead except a few null checks.

### 4.3 Custom Constructor Arguments
If your collector needs dependencies, you have two options:
1. Keep a zero‑argument constructor and resolve dependencies statically/singleton inside it.
2. Fork or extend `QueueRunCommand` overriding `getMetricsCollector()` to build it via your preferred container.

### 4.4 Worker Integration Points
Current built‑in emission sites:
| Location                    | Metric(s)                                    |
|----------------------------|-----------------------------------------------|
| `RequeueHelper::finalize`  | `jobs_succeeded`, `jobs_failed`, `jobs_requeued` |
| `QueueRunCommand::process` | `jobs_fetched`, `jobs_age_seconds`, `jobs_exec_seconds` |
| (Future timeout hook)      | `jobs_timed_out`                              |

You can safely add more in your own extended command or PRs.

---
## 5. Adding Custom Metrics (Examples)

You can instrument additional points such as execution duration, queue latency,
attempt counts, or domain numbers (e.g. rows processed).

### 5.1 Execution Duration
Inside the lifecycle (e.g. after an `ExecutionResult` is produced):
```php
$metrics?->observe('job_duration_seconds', $result->durationSeconds(), [
    'queue' => $queueName,
    'job'   => $job->getJob(),
    'success' => $result->success ? '1' : '0',
]);
```

### 5.2 Queue Latency (Enqueue → Start)
Store an `enqueuedAt` timestamp in your envelope when pushing, then at start:
```php
if (isset($envelope->enqueuedAt)) {
    $metrics?->observe('job_queue_latency_seconds', microtime(true) - $envelope->enqueuedAt, [
        'queue' => $envelope->queue,
        'job'   => $job->getJob(),
    ]);
}
```

### 5.3 Attempt Counter
```php
$metrics?->increment('job_attempts_total', 1, [
    'queue' => $queueName,
    'job'   => $job->getJob(),
]);
```

### 5.4 Domain / Business Metric
If a handler returns JSON encodable output with a field:
```php
// Suppose $result->output = '{"imported":523,"skipped":12}'
$data = json_decode($result->output ?? 'null', true);
if (is_array($data) && isset($data['imported'])) {
    $metrics?->increment('users_imported_total', (int)$data['imported'], [
        'job' => $job->getJob(),
    ]);
}
```

### 5.5 Timeout Counter
When you detect a soft/hard timeout:
```php
$metrics?->increment('jobs_timed_out', 1, [ 'queue' => $queueName ]);
```

---
## 6. Implementing Your Own Collector (Prometheus Example)

Example skeleton using `promphp/prometheus_client_php`:
```php
use Daycry\Jobs\Metrics\MetricsCollectorInterface;
use Prometheus\CollectorRegistry;

final class PrometheusCollector implements MetricsCollectorInterface
{
    public function __construct(private CollectorRegistry $registry) {}

    public function increment(string $counter, int $value = 1, array $labels = []): void
    {
        $c = $this->registry->getOrRegisterCounter('jobs', $counter, 'Jobs counter', array_keys($labels));
        $c->incBy($value, array_values($labels));
    }

    public function observe(string $metric, float $value, array $labels = []): void
    {
        $h = $this->registry->getOrRegisterHistogram('jobs', $metric, 'Jobs histogram', [0.1, 0.5, 1, 2, 5, 10], array_keys($labels));
        $h->observe($value, array_values($labels));
    }

    public function getSnapshot(): array
    {
        return []; // Not strictly needed; optional for debugging.
    }
}
```

Expose `/metrics` endpoint in your app and let Prometheus scrape it.

For StatsD / DogStatsD you would map `increment()` to `statsd->increment()` and `observe()` to
`statsd->histogram()` or timing calls.

---
## 7. Recommended Naming & Label Conventions

Follow common observability guidelines:

| Type        | Suffix              | Example                      |
|-------------|---------------------|------------------------------|
| Counter     | `_total` (optional) | `job_attempts_total`         |
| Duration    | `_seconds`          | `job_duration_seconds`       |
| Latency     | `_seconds`          | `job_queue_latency_seconds`  |

Suggested labels (keep cardinality modest):
* `queue` – backend queue name
* `job` – logical job key (e.g. `jobs:import:users`)
* `success` – `'1'`/`'0'` outcome flag (avoid booleans in some backends)
* `attempt` – optional attempt number (only if needed; can explode cardinality)

Avoid labels that can take unbounded values (timestamps, UUID per run) – that harms performance.

---
## 8. Extension Points & Ideas

| Metric Idea                | Source Hook                         |
|----------------------------|--------------------------------------|
| Execution duration         | After each `ExecutionResult`         |
| Queue latency              | Envelope: enqueuedAt vs start        |
| Retry delay distribution   | When computing backoff delay         |
| Callback chain depth       | In callback dispatch                 |
| Payload size bytes         | Before execution (strlen json_encode)|
| Output truncation count    | Where maxOutputLength applied        |
| Active single-instance lock| When acquiring / releasing           |

---
## 9. Troubleshooting

| Issue                                  | Cause / Fix |
|----------------------------------------|-------------|
| Counters always zero                   | Collector not injected (null). Ensure service wiring. |
| High memory usage with InMemory        | Long‑running worker + many unique label combos. Switch to streaming exporter. |
| Cardinality explosion                  | Too many distinct `job` or `attempt` labels. Trim labels. |
| Missing `jobs_timed_out` increments    | Timeout hook not yet implemented – add where you enforce timeouts. |
| Histogram buckets seem coarse          | Adjust bucket array in your custom collector implementation. |

---
## 10. Minimal End‑to‑End Example

```php
$metrics = new InMemoryMetricsCollector();
$requeue = new RequeueHelper($metrics); // now core counters fire

// After executing a job somewhere in your worker loop:
$metrics->observe('job_duration_seconds', 0.91, ['queue' => 'default', 'job' => 'jobs:cleanup']);

print_r($metrics->getSnapshot());
```

---
## 11. Summary

1. Provide a `MetricsCollectorInterface` implementation.
2. Inject it into `RequeueHelper` (or any other lifecycle component you extend).
3. Use `increment()` for discrete counts; `observe()` for timings / sizes.
4. Keep label sets small & stable.
5. Export using your monitoring backend of choice.

Feel free to open issues or PRs if you want deeper native instrumentation hooks.
