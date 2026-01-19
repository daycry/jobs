# CLI Commands

The package supplies several Spark commands to orchestrate scheduling, queue consumption and introspection.

## Cron / Scheduler
| Command | Description |
|---------|-------------|
| `jobs:cronjob:run` | Execute all due scheduled jobs immediately. |
| `jobs:cronjob:history <name> [--extended]` | Show recent executions (extended adds attempt, queue, payload hash, etc.). |
| `jobs:cronjob:enable <name>` | Enable a scheduled job by name. |
| `jobs:cronjob:disable <name>` | Disable a scheduled job by name. |
| `jobs:cronjob:list` | List registered cron jobs and their expressions. |

### Commands

**jobs:cronjob:list**

    > php spark jobs:cronjob:list

This will list all available tasks that have been defined in the project, along with their type and
the next time they are scheduled to run.

    +--------------------------+---------+-------------+---------------------+---------------------+
    | Name                     | Type    | Expression  | Last Run            | Next Run            |
    +--------------------------+---------+-------------+---------------------+---------------------+
    | job1                     | command | 08 10 * * * | --                  | 2022-11-04 10:08:00 |
    | Job2                     | command | 0 0 * * *   | 2022-10-28 13:23:21 | 2022-11-05 00:00:00 |
    +--------------------------+---------+-------------+---------------------+---------------------+

**jobs:cronjob:disable**

    > php spark jobs:cronjob:disable

Will disable the task runner manually until you enable it again. Writes a file to `{WRITEPATH}/cronJob` so 
you need to ensure that directory is writable. Default CodeIgniter permissions already have the WRITEABLE
path with write permissions. You should not need to change anything for this to work. 

**jobs:cronjob:enable**

    > php spark jobs:cronjob:enable

Will enable the task runner if it was previously disabled, allowing all tasks to resume running. 

**jobs:cronjob:run**

    > php spark jobs:cronjob:run


## Queues
| Command | Description |
|---------|-------------|
| `jobs:queue:run [--queue=NAME] [--sleep=N]` | Run a worker consuming jobs from configured backend. |
| `jobs:health [--json] [--queue=NAME]` | Display system health and queue statistics. |

### Queue Worker Details

The `jobs:queue:run` command starts a long-running process that:
1. Uses `QueueManager` to get the configured queue backend
2. Continuously calls `watch()` to fetch jobs
3. Executes jobs via `JobLifecycleCoordinator`
4. Handles retries, metrics, and logging
5. Sleeps between cycles (default: 1 second)

**Options**:
- `--queue=NAME` - Specific queue name to consume from (default: uses first in config)
- `--sleep=N` - Seconds to sleep between fetch cycles (default: 1)

**Queue Backends**:
The worker automatically uses the backend configured in `Config\Jobs::$worker`:
- `redis` - Fast in-memory queue with delayed job support
- `database` - Persistent relational storage
- `beanstalk` - Beanstalkd tube-based processing
- `servicebus` - Azure Service Bus integration
- `sync` - Inline execution (no background processing)

**Metrics**:
If metrics are enabled, the worker tracks:
- `jobs_fetched` - Total fetch attempts
- `jobs_age_seconds` - Queue latency (enqueue → start)
- `jobs_exec_seconds` - Execution duration
- Plus queue-level metrics if using `InstrumentedQueueDecorator`

## Health Monitoring

**jobs:health**

    > php spark jobs:health

Displays comprehensive system health and queue statistics.

**Options**:
- `--json` - Output in JSON format (machine-readable)
- `--queue=NAME` - Show statistics for a specific queue only

**Example Output** (table format):
```
=== Jobs System Health Check ===

Configuration:
  Retry Strategy: exponential (base: 60s, multiplier: 2.0, max: 3600s)
  Job Timeout: 300 seconds
  Dead Letter Queue: failed_jobs
  Rate Limits: default=50/min, high_priority=100/min

Queue: default
  Status:
    Pending: 42
    Processing: 3
    Completed: 1,245
    Failed: 12
  Rate Limit: 23/50 (46% used)
  Last 24h:
    Executions: 156
    Success Rate: 92.3%
    Failure Rate: 7.7%
    Avg Duration: 2.45s
```

**JSON Output** (`--json`):
```json
{
  "config": {
    "retry_strategy": "exponential",
    "job_timeout": 300,
    "dead_letter_queue": "failed_jobs",
    "rate_limits": {"default": 50}
  },
  "queues": {
    "default": {
      "status": {"pending": 42, "processing": 3, "completed": 1245, "failed": 12},
      "rate_limit": {"current": 23, "max": 50},
      "last_24h": {
        "executions": 156,
        "success_rate": 92.3,
        "avg_duration_seconds": 2.45
      }
    }
  }
}
```

**Use Cases**:
- Monitoring dashboards (JSON output to Prometheus/Grafana)
- Quick operational health checks
- Identifying bottlenecks and failure patterns
- Rate limit capacity planning


## Examples
Run Redis worker on specific queue:
```bash
php spark jobs:queue:run --queue=emails --sleep=2
```
View extended cron history:
```bash
php spark jobs:cronjob:history <name> --extended
```
Disable a cron job:
```bash
php spark jobs:cronjob:disable
```
