# CLI Commands

v3 ships exactly four Spark commands. Run them with `php spark <command>`.

| Command | Purpose |
|---------|---------|
| `jobs:queue:work` | Run a queue worker (long-running or one-shot). |
| `jobs:queue:reap` | Reclaim messages whose visibility timeout expired (crashed-worker recovery). |
| `jobs:cronjob:run` | Evaluate the schedule and run/enqueue due jobs (wire to system cron every minute). |
| `jobs:queue:purge` | Maintenance: delete completed/failed rows from the database backend table. |

> The v1 commands `jobs:queue:run`, `jobs:redis:reap-stuck`, `jobs:cronjob:enable`,
> `jobs:cronjob:disable`, `jobs:cronjob:list`, `jobs:cronjob:history` and `jobs:health` were
> **removed** in v3.0. They no longer exist. See the migration guide for replacements.

## jobs:queue:work

Pulls messages from the configured backend and drives each through the worker pipeline
(`fetch -> verify signature -> idempotency guard -> run one attempt -> ack / nack(backoff) /
abandon`).

```text
Usage: jobs:queue:work [queue] [--once] [--max N] [--backend name]
```

| Argument / Option | Description |
|-------------------|-------------|
| `queue` | Queue name. Defaults to the first queue in `Config\Jobs::$queues`. |
| `--once` | Process a single cycle and exit. |
| `--max N` | Process at most N cycles then exit (0 = unlimited). |
| `--backend name` | Override the configured backend (`$worker`). |

Behaviour:

- **Graceful shutdown**: SIGTERM/SIGINT finish the current cycle and exit (POSIX, requires `pcntl`).
  A job in flight is never aborted mid-execution; the worker simply stops fetching the next one.
- **Circuit breaker**: after `circuitBreakerThreshold` consecutive backend errors the circuit opens
  and the worker backs off for `circuitBreakerCooldown` seconds.
- **Rate limiting**: per-queue caps from `Config\Jobs::$queueRateLimits` (jobs/min) are honoured.
- **Idle polling**: when a cycle yields nothing (`empty`, `rate-limited`, `circuit-open`, `error`)
  the worker sleeps `Config\Jobs::$pollInterval` seconds. With `blockingFetch = true` on a supporting
  backend the fetch blocks instead of polling.

Examples:

```bash
# Long-running worker on the first configured queue
php spark jobs:queue:work

# Named queue, one cycle then exit (cron-friendly)
php spark jobs:queue:work reports --once

# Bound to 500 cycles on the redis backend
php spark jobs:queue:work emails --max 500 --backend redis
```

Run a worker under a process supervisor (systemd, Supervisor) for production so it is restarted on
exit. For cron-style processing use `--once` or `--max N`.

## jobs:queue:reap

Reclaims queue messages whose visibility timeout expired (a worker crashed or stalled between fetch
and ack), returning them to the ready state via `QueueBackend::reapExpired()`.

```text
Usage: jobs:queue:reap [queue] [--backend name]
```

| Argument / Option | Description |
|-------------------|-------------|
| `queue` | Queue name to reap (**required**). |
| `--backend name` | Override the configured backend. |

The visibility timeout applied is `redisProcessingVisibilityTimeout` when the backend is `redis`, and
`databaseVisibilityTimeout` otherwise (both default to 300s).

Needed for the **database** and **redis** backends; **beanstalk** and **serviceBus** recover in-flight
messages natively when their lock/reservation expires. Run it periodically via system cron:

```bash
# every minute, recover stranded messages on the 'reports' queue
* * * * * cd /path/to/project && php spark jobs:queue:reap reports >> /dev/null 2>&1

# explicit backend
php spark jobs:queue:reap emails --backend redis
```

## jobs:cronjob:run

Evaluates the scheduled definitions registered in `Config\Jobs::init()` and acts on the due ones —
**enqueuing** those with a `queue()` and **running inline** those without. Wire it to system cron to
run every minute.

```text
Usage: jobs:cronjob:run [options]
```

| Option | Description |
|--------|-------------|
| `-testTime <iso>` | Evaluate the schedule against a frozen datetime (testing/dry-run). |

```bash
# Run due jobs now
php spark jobs:cronjob:run

# Crontab: every minute
* * * * * cd /path/to/project && php spark jobs:cronjob:run >> /dev/null 2>&1

# Evaluate against a frozen time
php spark jobs:cronjob:run -testTime "2026-06-03 02:00:00"
```

See [Scheduling](scheduling.md) for how to register jobs, frequency helpers, `enabled`/`disable`,
environments and dependencies.

## jobs:queue:purge

Deletes completed and/or failed rows from the **database** backend table — essential maintenance, as
the table grows indefinitely otherwise.

```text
Usage: jobs:queue:purge [options]
```

| Option | Description |
|--------|-------------|
| `--status` | `completed`, `failed`, or `all`. Default `completed`. |
| `--queue` | Restrict to a single queue. |
| `--before` | Only rows created before this date (e.g. `"2026-01-01"` or `"-7 days"`). |
| `--dry-run` | Show the count without deleting. |
| `--force` | Skip the confirmation prompt. |

```bash
# Preview how many completed jobs would be removed
php spark jobs:queue:purge --status completed --dry-run

# Purge failed jobs older than 7 days in the 'reports' queue, no prompt
php spark jobs:queue:purge --status failed --queue reports --before "-7 days" --force
```
