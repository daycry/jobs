# Migrating from v1 to v2

> **Status (v1.2 release)**: the v2 namespace `Daycry\Jobs\V2\` ships **alongside** the v1
> API. Existing code keeps working unchanged; v2 is opt-in. The legacy mutable `Job` builder
> and the split `QueueInterface`/`WorkerInterface` will be deprecated in v1.3 and removed in
> a future major release.

This document describes the gradual adoption path so a codebase can migrate
file-by-file without a big-bang rewrite.

## What changes in v2

| Concern | v1 | v2 |
|---|---|---|
| Job description | Mutable `Daycry\Jobs\Job` builder with ~50 fluent methods | Immutable `Daycry\Jobs\V2\JobDefinition` value object (`withXxx()` returns a new copy) |
| Queue interface | Two coupled interfaces: `QueueInterface::enqueue()` + `WorkerInterface::watch()`/`removeJob()` (per-instance state) | Single `Daycry\Jobs\V2\Queues\QueueBackend` with `enqueue / fetch / ack / nack / abandon` |
| In-flight tracking | Backend instance member `$this->job` | Explicit `Daycry\Jobs\V2\Queues\JobLease` value passed back to `ack/nack/abandon` |
| Handler payload | `mixed` — every handler unpacks defensively | `Daycry\Jobs\V2\Handlers\TypedJobHandler` rehydrates a declared DTO |
| Service locator | `service('email')`, `service('cache')`, etc. directly inside domain objects | DI via constructor (with `Config\Services` factories preserving the locator path for callers that want it) |

## What does NOT change in v1.x

- Cron scheduling, retries, callbacks, masking, metrics, ServiceBus peek-lock and Redis
  reliable queue (added in v1.1) all keep their existing APIs.
- Existing handlers, schedulers, and `php spark jobs:queue:run` invocations work as before.
- `Config\Jobs.php` is untouched: `$jobs`, `$workers`, `$worker`, `$queues`, etc. stay the same.

## Step-by-step adoption

### 1. Wrap an existing backend as v2

The fastest way to start using the v2 contract without rewriting any backend:

```php
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\V2\JobDefinition;
use Daycry\Jobs\V2\Queues\LegacyWorkerAdapter;

$legacy   = QueueManager::instance()->getDefault();           // your existing redis/database/etc.
$adapter  = new LegacyWorkerAdapter($legacy, 'redis', 300);    // 300s lease

$definition = new JobDefinition(handler: 'command', payload: 'jobs:test');
$id         = $adapter->enqueue($definition->withQueue('reports')->withMaxRetries(3));

// Worker side
while ($lease = $adapter->fetch('reports')) {
    try {
        // ... process $lease->envelope->payload ...
        $adapter->ack($lease);
    } catch (\Throwable) {
        $adapter->nack($lease);
    }
}
```

The adapter holds at most ONE in-flight lease per instance (legacy WorkerInterface state
limitation). Use one adapter per worker and you preserve the existing semantics for free.

### 2. Author handlers with typed DTOs

```php
use Daycry\Jobs\V2\Handlers\TypedJobHandler;

final class ImportRequest
{
    public function __construct(
        public string $source,
        public int $rows,
    ) {}
}

final class ProcessImport extends TypedJobHandler
{
    public function payloadType(): string
    {
        return ImportRequest::class;
    }

    protected function run(object $payload): mixed
    {
        /** @var ImportRequest $payload */
        return "imported {$payload->rows} from {$payload->source}";
    }
}
```

Register it in `Config\Jobs::$jobs` exactly like a legacy handler. Payloads can be sent as
arrays / stdClass / JSON strings; the handler rehydrates them through the DTO constructor.

### 3. Use `JobDefinition::fromLegacyJob()` during the transition

When mixing v1 and v2 sites:

```php
$legacyJob  = (new \Daycry\Jobs\Job(job: 'command', payload: 'x'))
    ->named('legacy_demo')
    ->setQueue('reports')
    ->maxRetries(3);

$definition = JobDefinition::fromLegacyJob($legacyJob);  // immutable snapshot
$adapter->enqueue($definition);
```

This gives you a single canonical value object even when older code still produces a
mutable `Job`. Once every call site has migrated to building `JobDefinition` directly, the
legacy bridge can be removed.

### 4. Replace service-locator calls in your handlers (v1.2)

The notification logic was extracted to `Daycry\Jobs\Notifications\NotificationService` so
you can mock the email transport in tests:

```php
use Daycry\Jobs\Notifications\NotificationService;

$svc = new NotificationService(
    email: $mockEmail,
    parser: $mockParser,
    from: 'jobs@example.com',
    fromName: 'Jobs',
    to: 'oncall@example.com',
    view: 'Daycry\Jobs\Views\email_notification',
    timezone: 'UTC',
);

// Inject explicitly to bypass the service locator entirely:
$result = $job->notify($executionResult, $svc);
```

`JobLogger::__construct` accepts an optional handler and a deterministic execution id for
the same testing reason.

## Roadmap to native v2 backends

The current `LegacyWorkerAdapter` is a stop-gap. Future minor releases will ship native v2
implementations of each backend, expose their delay-aware nack semantics, and lift the
"one in-flight lease per adapter" restriction. Once those are available, integrators can
swap the adapter for the native backend without changing call sites:

```php
// future v1.3
use Daycry\Jobs\V2\Queues\RedisBackend;

$backend = new RedisBackend(/* config */);
$lease   = $backend->fetch('default');
```

## Deprecation timeline (proposed)

- **v1.2 (current)** — v2 namespace ships; everything in v1 keeps working.
- **v1.3** — native v2 backend(s) for at least Redis and Database; `LegacyWorkerAdapter`
  becomes the recommended bridge for `ServiceBusQueue` and `BeanstalkQueue` only.
- **v2.0** — legacy `QueueInterface` / `WorkerInterface` and the mutable `Job` builder are
  marked `@deprecated`. Existing code still works through compatibility shims.
- **v3.0** — legacy API removed. Migration path documented in the upgrade guide.

No timeline is committed beyond v1.2; the schedule above is intent-of-direction and may
shift based on community uptake of the v2 API.
