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
| `jobs:queue:run [--queue=NAME] [--sleep=N]` | Run a worker consuming jobs. |


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
