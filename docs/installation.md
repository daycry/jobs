# Installation

## Requirements
- PHP >= 8.1
- CodeIgniter 4
- Composer

## Steps
```bash
composer require daycry/jobs
php spark jobs:publish
php spark migrate -all
```

Configure queues, worker, retries in `app/Config/Jobs.php`.

Run cron worker:
```bash
php spark jobs:cronjob:run
```
Run queue worker:
```bash
php spark jobs:queue:run
```

Next: [Defining Schedules](scheduling.md)
