# Defining Schedules

Use the scheduler in `app/Config/Jobs.php::init()`.

## Basic Example
```php
$scheduler->command('jobs:test')
    ->named('test_job')
    ->everyMinute()
    ->singleInstance()
    ->enqueue();
```

## Frequency Helpers
Common helpers: `everyMinute()`, `hourly()`, `daily('11:00 pm')`, `cron('* * * * *')`, `weekly()`, etc.
Refer to README frequency table.

## Single Instance
`->singleInstance()` prevents concurrent overlapping runs.

## Notifications
```php
$scheduler->command('report:generate')
  ->named('rpt')
  ->daily('6:00 am')
  ->notifyOnCompletion();
```

Next: [Job Dependencies](dependencies.md)
