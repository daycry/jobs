# CLI Commands

v3 ships **five** Spark commands, all in the `Jobs` group. Run them with
`php spark <command>`. This page is the complete reference: arguments, options, examples, exit codes
and graceful-shutdown behaviour.

| Command | Purpose |
|---------|---------|
| [`jobs:queue:work`](#jobsqueuework) | Run a queue worker (long-running or one-shot). |
| [`jobs:queue:reap`](#jobsqueuereap) | Reclaim messages whose visibility timeout expired (crashed-worker recovery). |
| [`jobs:cronjob:run`](#jobscronjobrun) | Evaluate the schedule and run/enqueue due jobs (wire to system cron every minute). |
| [`jobs:queue:purge`](#jobsqueuepurge) | Maintenance: delete completed/failed rows from the database backend table. |
| [`jobs:publish`](#jobspublish) | Publish the `Config\Jobs` file into the application namespace for customization. |

> **Note — retired commands.** The v1 commands `jobs:queue:run`, `jobs:redis:reap-stuck`,
> `jobs:cronjob:enable`, `jobs:cronjob:disable`, `jobs:cronjob:list`, `jobs:cronjob:history` and
> `jobs:health` were **removed** in v3.0 and no longer exist as commands. Use `jobs:queue:work`
> instead of `jobs:queue:run`, and `jobs:queue:reap` instead of `jobs:redis:reap-stuck`. Per-job
> enable/disable is now a property on the definition (`enabled()`/`disable()` — see
> [Scheduling](scheduling.md)). See the [Migration guide](MIGRATION-v1-to-v3.md) for the full mapping.

> **Known issue — global `jobs_active` gate.** Separate from the per-definition `enabled()`/`disable()`
> property, `BaseJobsCommand` still carries the v1 **global** on/off mechanism: a cache flag named
> `jobs_active` that gates `jobs:cronjob:run` (see [its Behaviour](#behaviour-2) below). No shipped
> command toggles this flag — the `jobs:cronjob:enable`/`jobs:cronjob:disable` commands that would set
> it were removed. As a result, when the flag is unset the runtime prints the **stale** hint
> `**** To re-enable tasks run: jobs:cronjob:enable ****`, which references a command that no longer
> exists. There is currently no supported command to set `jobs_active` to `enabled`; see the
> note in [`jobs:cronjob:run`](#jobscronjobrun) for the practical impact.

## jobs:queue:work

Starts a queue worker. It pulls messages from the configured backend and drives each one through the
worker pipeline:

```text
fetch -> verify signature -> idempotency guard -> run one attempt -> ack / nack(backoff) / abandon
```

```text
Usage: jobs:queue:work [queue] [--once] [--max N] [--backend name]
```

| Argument / Option | Description |
|-------------------|-------------|
| `queue` | Queue name. Defaults to the **first** queue in `Config\Jobs::$queues`. |
| `--once` | Process a single cycle and exit (equivalent to `--max 1`). |
| `--max N` | Process at most `N` cycles then exit (`0` / omitted = unlimited / run forever). |
| `--backend name` | Override the configured default backend (`Config\Jobs::$worker`). |

### Behaviour

- **Graceful shutdown.** On `SIGTERM`/`SIGINT` (POSIX, requires the `pcntl` extension) the worker
  sets a stop flag, finishes the **current** cycle, prints `graceful shutdown complete.` and exits.
  A job in flight is **never** aborted mid-execution — the worker simply stops fetching the next one.
  On platforms without `pcntl` (e.g. Windows) signal handling is skipped; bound the run with
  `--once`/`--max` or stop the process externally.
- **Circuit breaker.** Backend errors are tracked per queue. After
  `Config\Jobs::$circuitBreakerThreshold` consecutive failures the circuit opens and the worker skips
  attempts for `Config\Jobs::$circuitBreakerCooldown` seconds (then probes once). Reported as
  `[Circuit Open] ...`.
- **Rate limiting.** Per-queue caps from `Config\Jobs::$queueRateLimits` (jobs/minute) are honoured;
  a throttled cycle reports `[Rate Limited] ...`.
- **Idle polling.** When a cycle yields nothing actionable (`empty`, `rate-limited`, `circuit-open`,
  `error`) the worker sleeps `max(1, Config\Jobs::$pollInterval)` seconds before the next cycle.

Each non-empty cycle prints a line like `[acked] reports (attempt 1)` or
`[requeued] reports (attempt 2) - <error>`.

### Examples

```bash
# Long-running worker on the first configured queue, default backend
php spark jobs:queue:work

# Named queue, single cycle then exit (cron-friendly / one-shot)
php spark jobs:queue:work reports --once

# Bound to 500 cycles on the redis backend
php spark jobs:queue:work emails --max 500 --backend redis
```

> **Note:** For production, run a long-running worker under a process supervisor (systemd or
> Supervisor) so it is restarted on exit. For cron-style processing use `--once` or `--max N`. See
> [Operations](operations.md) for full supervisor/systemd examples.

### Exit codes

| Code | Meaning |
|------|---------|
| `0` (`SUCCESS`) | The worker stopped normally (reached `--once`/`--max`, or shut down gracefully). |

The command always returns `SUCCESS` on a clean stop; transient backend errors are handled
internally by the circuit breaker rather than aborting the process.

## jobs:queue:reap

Reclaims queue messages whose visibility timeout expired (a worker crashed or stalled between
`fetch()` and `ack()`), returning them to the ready state via `QueueBackend::reapExpired()`.

```text
Usage: jobs:queue:reap [queue] [--backend name]
```

| Argument / Option | Description |
|-------------------|-------------|
| `queue` | Queue name to reap (**required**). |
| `--backend name` | Override the configured default backend. |

The visibility timeout applied is `Config\Jobs::$redisProcessingVisibilityTimeout` when the backend
is `redis`, and `Config\Jobs::$databaseVisibilityTimeout` otherwise (both default to `300` seconds).

Needed for the **database** and **redis** backends. **beanstalk** and **serviceBus** recover
in-flight messages natively when their reservation/lock expires, so reaping them is a harmless no-op
(returns `0`). Run it periodically via system cron:

```bash
# Every minute, recover stranded messages on the 'reports' queue
* * * * * cd /path/to/project && php spark jobs:queue:reap reports >> /dev/null 2>&1

# Explicit backend
php spark jobs:queue:reap emails --backend redis
```

On success it prints `Reaped N expired message(s) from queue '<queue>'.`

### Exit codes

| Code | Meaning |
|------|---------|
| `0` (`SUCCESS`) | Reaping completed (zero or more messages recovered). |
| `1` (`FAILURE`) | No queue name was provided. |

## jobs:cronjob:run

Evaluates the scheduled definitions registered in `Config\Jobs::init()` and acts on the due ones —
**enqueuing** those that declare a `queue()` and **running inline** those that do not. Wire it to
system cron to run **every minute**.

```text
Usage: jobs:cronjob:run [options]
```

| Option | Description |
|--------|-------------|
| `-testTime <iso>` | Evaluate the schedule against a frozen ISO datetime (deterministic dry-run / testing). |

```bash
# Run due jobs now
php spark jobs:cronjob:run

# Crontab: every minute
* * * * * cd /path/to/project && php spark jobs:cronjob:run >> /dev/null 2>&1

# Evaluate against a frozen time (does not change the system clock; only affects which jobs are "due")
php spark jobs:cronjob:run -testTime "2026-06-03 02:00:00"
```

See [Scheduling](scheduling.md) for registering jobs, frequency helpers, `enabled()`/`disable()`,
environments, dependencies and the queued-vs-inline rule.

### Behaviour

- **Global `jobs_active` gate.** Before evaluating the schedule, the command checks
  `BaseJobsCommand::isActive()`, which reads the cache flag `jobs_active`. If the flag is missing or
  not set to `enabled`, the command **short-circuits**: it prints a warning, does **nothing** (no jobs
  are evaluated, enqueued or run), and still returns `SUCCESS` (`0`).
- **No shipped command enables the flag.** The v1 `jobs:cronjob:enable`/`jobs:cronjob:disable`
  commands were removed (see the *Known issue* note at the top of this page), so out of the box the
  flag is unset and **`php spark jobs:cronjob:run` is a no-op** — the crontab entry below will run but
  process nothing. The runtime hint `To re-enable tasks run: jobs:cronjob:enable` is stale and points
  at a command that no longer exists. Until a supported toggle is reintroduced, set the flag yourself
  (e.g. from a bootstrap/migration or a small app command) by caching a `jobs_active` value whose
  `status` property is `enabled`:

  ```php
  $settings = new \stdClass();
  $settings->status     = 'enabled';
  $settings->updated_at = new \DateTime();
  service('cache')->save('jobs_active', $settings, 0); // 0 = never expires
  ```

### Exit codes

| Code | Meaning |
|------|---------|
| `0` (`SUCCESS`) | The run completed (due jobs were enqueued / run inline) **or** processing was globally disabled via the `jobs_active` gate, in which case nothing ran. |

## jobs:queue:purge

Deletes completed and/or failed rows from the **database** backend table (`Config\Jobs::$database['table']`,
default `queues`). Essential maintenance, since the table grows indefinitely otherwise (acked rows
become `completed`, abandoned rows become `failed`).

```text
Usage: jobs:queue:purge [options]
```

| Option | Description |
|--------|-------------|
| `--status` | `completed`, `failed`, or `all`. Default `completed`. (`all` matches both `completed` and `failed`.) |
| `--queue` | Restrict to a single queue. |
| `--before` | Only rows created before this date — any `strtotime()`-parseable value (e.g. `"2026-01-01"` or `"-7 days"`). |
| `--dry-run` | Show the matching count without deleting. |
| `--force` | Skip the interactive confirmation prompt. |

```bash
# Preview how many completed jobs would be removed (no deletion)
php spark jobs:queue:purge --status completed --dry-run

# Purge failed jobs older than 7 days in the 'reports' queue, no prompt
php spark jobs:queue:purge --status failed --queue reports --before "-7 days" --force

# Purge everything terminal (completed + failed) across all queues, with confirmation prompt
php spark jobs:queue:purge --status all
```

Behaviour:

- An invalid `--status` value, or an unparseable `--before` value, prints an error and exits without
  deleting.
- When no rows match, it prints `No matching jobs found to purge.` and exits.
- Without `--force` it asks `Are you sure you want to delete these records?` and cancels unless you
  answer `y`.

> **Warning:** `jobs:queue:purge` only operates on the **database** backend table. It does not touch
> redis keys, beanstalkd tubes or Service Bus entities. It targets terminal rows (`completed`,
> `failed`) — it never deletes `pending` or `in_progress` rows, so an in-flight or queued job is
> safe.

> **Note:** This command extends `CodeIgniter\CLI\BaseCommand` and returns `void` (no explicit exit
> code); it terminates with the default success status. Schedule it from system cron for routine
> maintenance — see [Operations](operations.md).

## jobs:publish

Copies the package's `Config\Jobs` file into your application's namespace (`app/Config/Jobs.php`) so
you can customize it. The published class extends `Daycry\Jobs\Config\Jobs`, so it inherits every
default and only overrides what you change. Run it once after installing the package.

```text
Usage: jobs:publish
```

This command takes no arguments or options. It prompts `Publish Config file?` (`y`/`n`); answer `y`
to write the file. On success it prints the created path (e.g. `created: Config/Jobs.php`).

```bash
# Publish the config into app/Config/Jobs.php
php spark jobs:publish
```

> **Note:** Like `jobs:queue:purge`, this command returns `void` (no explicit exit code) and
> terminates with the default success status. See [Configuration](CONFIGURATION.md) for every
> setting you can override in the published file.

## See also

- [Queues & Backends](QUEUES.md) — the worker pipeline, lease semantics and backends.
- [Scheduling](scheduling.md) — registering and evaluating cron jobs.
- [Operations](operations.md) — supervisors, the reaper, scaling and observability.
- [Configuration](CONFIGURATION.md) — every worker, reaper and backend setting referenced above.
