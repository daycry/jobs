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
