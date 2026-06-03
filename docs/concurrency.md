# Concurrency & Resilience

When you run more than one worker (or one worker pulling a fast queue), several jobs execute at the
same time. v3 ships three mechanisms to keep concurrent execution safe and to protect the worker
and downstream systems from overload:

- **Single-instance locking** — guarantee a named job never runs twice at once.
- **Circuit breaker** — stop hammering a backend that is failing.
- **Rate limiting** — cap how many jobs a queue processes per minute.

This page explains how each works, how it is wired, and how to configure it. See
[Configuration](CONFIGURATION.md) for the property reference and [Security](security.md) for the
trust-boundary controls.

## Single-instance locking

A job marked `singleInstance()` must never have two attempts running concurrently — for example a
nightly aggregation that would corrupt data if it overlapped with itself.

The mechanism is `Daycry\Jobs\Execution\SingleInstanceLock`, an **ownership-token** cache lock:
only the holder that acquired the lock can release it.

### How it is wired in JobRuntime

`JobRuntime::run()` acquires the lock before invoking the handler and releases it in a `finally`
block, so a thrown exception or timeout still frees the lock:

```php
$lockName  = $context->name ?? $definition->name ?? $definition->handler;
$lockOwner = '';
$locked    = false;

if ($definition->singleInstance) {
    $lockOwner = bin2hex(random_bytes(16)); // ownership token unique to this attempt
    if (! $this->lock->acquire($lockName, $lockOwner, max(120, $timeoutSeconds + 60))) {
        // Already held by another attempt -> fail this one so the worker requeues it.
        return new ExecutionResult(false, null,
            "single-instance job '{$lockName}' is already running", $start, microtime(true), $handlerClass);
    }
    $locked = true;
}

try {
    // beforeRun / handle (under timeout) / afterRun ...
} finally {
    if ($locked) {
        $this->lock->release($lockName, $lockOwner);
    }
}
```

Key points:

- **Lock name** is the logical job name (`context->name`, else `definition->name`, else the handler
  key). Two definitions with the same name share a lock.
- **Ownership token** is a fresh 16-byte random value per attempt. `release()` only deletes the key
  when the stored owner matches, so a lock that was reaped and reassigned to another worker is never
  freed by the previous owner.
- **TTL** is `max(120, timeoutSeconds + 60)` seconds. The lock outlives the job's timeout, so a
  crashed worker's lock eventually expires instead of wedging the job forever.

### Contention behaviour → requeue

When `acquire()` returns `false` (the lock is held by a different owner), the attempt does **not**
block or sleep. It returns a **failed** `ExecutionResult`. The worker then treats it like any other
failure:

- with retries left → `nack(delay)` so the message is requeued and tried again later (by which time
  the holder may have finished);
- with retries exhausted → `abandon()`.

> **Note:** Because contention is surfaced as a normal failure, give single-instance jobs a few
> `maxRetries` and a backoff strategy so a transient overlap simply retries instead of dead-lettering.
> See [Retries](RETRIES.md).

### Atomicity caveat

```php
public function acquire(string $name, string $owner, int $ttl): bool
{
    $cache   = $this->resolveCache();
    $key     = self::KEY_PREFIX . $name; // 'jobs_lock_' . $name
    $current = $cache->get($key);
    if ($current !== null && $current !== $owner) {
        return false;
    }
    $cache->save($key, $owner, $ttl);
    return true;
}
```

The check-then-set is **not strictly atomic** unless the cache driver provides native `SET NX`
semantics. Between the read and the write a competing process could squeeze in. The ownership token
still guarantees that a `release()` never frees a lock held by a different owner — the property that
matters for cleanup correctness — but for the strongest mutual exclusion use a Redis-backed cache.

### Example

```php
use Daycry\Jobs\Jobs;

Jobs::define('command', 'app:rebuild-search-index')
    ->named('rebuild-search-index') // the lock name
    ->queue('maintenance')
    ->singleInstance()
    ->maxRetries(3)
    ->dispatch();
```

You can also mark scheduled jobs single-instance in `Config\Jobs::init()`:

```php
$scheduler->define('command', 'app:aggregate')
    ->named('nightly-aggregate')
    ->dailyAt('01:00')
    ->singleInstance();
```

## Circuit breaker

When the queue backend itself is failing (broker down, database unreachable), retrying every cycle
just wastes resources and floods logs. `Daycry\Jobs\Libraries\CircuitBreaker` short-circuits the
worker for a cooldown period after a run of failures.

### States

| State | Meaning |
|-------|---------|
| `closed` | Normal operation. Failures are counted. |
| `open` | Backend considered unavailable. Cycles are skipped for the cooldown window. |
| `half_open` | Cooldown elapsed; **one probe** is allowed to test recovery. |

State is stored in the cache service, so it persists across worker restarts (keyed
`circuit_<name>_*`).

### Transitions

- In `closed`, each `recordFailure()` increments a counter. When it reaches `failureThreshold`, the
  circuit **trips** to `open` and stamps `opened_at`.
- In `open`, `isAvailable()` returns `false` until `cooldownSeconds` have elapsed; then it transitions
  to `half_open` and allows one probe through (returns `true`).
- In `half_open`, a `recordSuccess()` resets to `closed`; a `recordFailure()` re-opens the circuit.

### How the worker uses it

`jobs:queue:work` builds a breaker named `queue_<queue>` per cycle and checks it before fetching:

```php
$breaker = new CircuitBreaker('queue_' . $queue,
    $this->config->circuitBreakerThreshold,
    $this->config->circuitBreakerCooldown);

if (! $breaker->isAvailable()) {
    CLI::write("[Circuit Open] backend for '{$queue}' temporarily unavailable.", 'red');
    return 'circuit-open'; // worker sleeps pollInterval, then retries
}

try {
    $result = $worker->processOnce($queue);
    $breaker->recordSuccess();
} catch (Throwable $e) {
    $breaker->recordFailure();   // a thrown backend error counts toward the threshold
    return 'error';
}
```

Only **thrown backend errors** (e.g. a connection failure inside `processOnce()`) count as failures.
A job whose *handler* fails is a normal failed attempt and does **not** trip the breaker — handler
failures are managed by the retry/DLQ path instead.

### Configuration

| Property | Default | Description |
|----------|---------|-------------|
| `$circuitBreakerThreshold` | `5` | Consecutive backend failures before the circuit opens. |
| `$circuitBreakerCooldown` | `60` | Seconds the circuit stays open before allowing a probe. |

```php
public int $circuitBreakerThreshold = 5;
public int $circuitBreakerCooldown  = 60;
```

When the circuit is open the worker sleeps `pollInterval` seconds between cycles (the `circuit-open`
status is treated like an empty queue for sleep purposes).

## Rate limiting

`Config\Jobs::$queueRateLimits` caps how many jobs a queue may process per minute, protecting
fragile downstream systems (third-party APIs, mail relays) from bursts.

`Daycry\Jobs\Libraries\RateLimiter` implements a cache-based, per-minute token window. The worker
checks it at the start of every cycle:

```php
$limit = $this->config->queueRateLimits[$queue] ?? 0;
if ($limit > 0 && ! (new RateLimiter())->allow($queue, $limit)) {
    CLI::write("[Rate Limited] '{$queue}' reached {$limit} jobs/min.", 'yellow');
    return 'rate-limited'; // worker sleeps pollInterval, then retries the cycle
}
```

### Configuration

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$queueRateLimits` | `array<string, int>` | `[]` | Max jobs per minute per queue. `0` or missing = unlimited. |

```php
public array $queueRateLimits = [
    'mailers'    => 60,   // at most 60 emails/min
    'thirdparty' => 100,
];
```

### Accuracy and the atomic vs fallback path

- On cache drivers with **atomic `increment()`** (Redis, Memcached, APCu) the counter is server-side
  atomic. A burst of workers may overshoot the limit by **at most one** before the next call throttles
  correctly — the standard token-bucket relaxation.
- On drivers **without** atomic increment (file/dummy/null) `RateLimiter` falls back to a best-effort
  read-modify-write that can undercount by one under a race.

> **Note:** For accurate production rate limiting use a Redis or Memcached cache handler. The limit
> is a throttle, not a hard quota — treat small overshoots as expected.

`RateLimiter` also exposes `getUsage($queue)`, `reset($queue)`, and a `throttle()` variant that
throws `JobException::forRateLimitExceeded()` instead of returning a boolean.

## Putting it together

A single worker cycle (`jobs:queue:work`) applies the controls in this order:

1. **Rate limit** — if the queue is over its per-minute cap, skip (`rate-limited`).
2. **Circuit breaker** — if the backend circuit is open, skip (`circuit-open`).
3. **Fetch + process one message** through `QueueWorker` (which verifies the signature, applies the
   single-instance lock inside `JobRuntime`, runs one attempt, and acks/nacks/abandons).
4. **Record** success/failure on the breaker.
5. On `empty`, `rate-limited`, `circuit-open`, or `error`, **sleep** `pollInterval` seconds before the
   next cycle.

For the attempt/retry model see [Attempts](ATTEMPTS.md) and [Retries](RETRIES.md); for tampering and
handler-execution controls see [Security](security.md).
