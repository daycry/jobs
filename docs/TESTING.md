# Testing Guide

This document describes the test organization and how to run tests for the Jobs package.

## Test Structure

Tests are organized under `tests/Unit/` by functional domain:

```
tests/
├── Unit/
│   ├── Callbacks/          - Callback chain, filters, callback jobs
│   ├── Commands/           - CLI commands (cronjob, queue:run, test)
│   ├── Execution/          - Job execution lifecycle, strategies
│   ├── Helpers/            - DateTime, Requeue helpers
│   ├── Jobs/               - Job core, envelopes, enqueue operations
│   ├── Logging/            - File/Database handlers, masking, rotation
│   ├── Metrics/            - Metrics collector
│   ├── Queues/             - Queue backends (Redis, Database, Beanstalk, ServiceBus)
│   ├── Retry/              - Retry policies, backoff strategies
│   ├── Scheduler/          - Cron scheduling
│   └── Traits/             - Job traits (Activity, etc.)
└── _support/               - Test helpers and base classes
```

## Running Tests

### Full Test Suite
```bash
vendor/bin/phpunit
```

### With Test Documentation Output
```bash
vendor/bin/phpunit --testdox
```

### Specific Test Category
```bash
# Queue tests only
vendor/bin/phpunit tests/Unit/Queues

# Retry policy tests
vendor/bin/phpunit tests/Unit/Retry

# Single test file
vendor/bin/phpunit tests/Unit/Jobs/JobBasicTest.php
```

### With Coverage
```bash
vendor/bin/phpunit --coverage-html build/coverage/html
```

## Test Requirements

### Redis Tests
Redis tests require a running Redis server:
- Host: `127.0.0.1` (or `REDIS_HOST` env var)
- Port: `6379` (or `REDIS_PORT` env var)

If Redis is unavailable, related tests will be skipped automatically.

### Beanstalk Tests
Beanstalk tests require beanstalkd running:
- Host: `127.0.0.1`
- Port: `11300`

If unavailable, tests are skipped.

### Database Tests
Database tests use CodeIgniter's test database configuration.
Migrations are run automatically via test setup.

## Writing Tests

### Test Organization Guidelines

1. **Place tests in appropriate category folder**
   - Queue backend tests → `tests/Unit/Queues/`
   - Command tests → `tests/Unit/Commands/`
   - Helper utilities → `tests/Unit/Helpers/`

2. **Naming convention**: `{Feature}{Aspect}Test.php`
   - Example: `RedisQueueCycleTest.php`, `RetryPolicyExponentialTest.php`

3. **Use TestCase base class**
   ```php
   use Tests\Support\TestCase;
   
   final class MyFeatureTest extends TestCase
   {
       protected function setUp(): void
       {
           parent::setUp();
           // Your setup
       }
   }
   ```

4. **Reset QueueManager in setUp**
   For tests using queues, reset the singleton cache:
   ```php
   use Daycry\Jobs\Libraries\QueueManager;
   
   protected function setUp(): void
   {
       parent::setUp();
       QueueManager::reset();
   }
   ```

### Common Test Patterns

#### Testing Queue Operations
```php
use Daycry\Jobs\Libraries\QueueManager;

public function testEnqueueReturnsId(): void
{
    $queue = QueueManager::instance()->get('database');
    $data = (object) ['job' => 'test', 'queue' => 'default', 'payload' => 'data'];
    
    $id = $queue->enqueue($data);
    
    $this->assertIsString($id);
    $this->assertNotEmpty($id);
}
```

#### Testing Job Execution
```php
use Daycry\Jobs\Job;
use Daycry\Jobs\Execution\JobLifecycleCoordinator;

public function testJobExecutesSuccessfully(): void
{
    $job = new Job(job: 'command', payload: 'jobs:test');
    $coordinator = new JobLifecycleCoordinator();
    
    $result = $coordinator->run($job)->finalResult;
    
    $this->assertTrue($result->success);
    $this->assertNull($result->error);
}
```

#### Testing Metrics
```php
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Libraries\InstrumentedQueueDecorator;

public function testMetricsAreTracked(): void
{
    $metrics = new InMemoryMetricsCollector();
    $instrumented = new InstrumentedQueueDecorator($queue, $metrics, 'test');
    
    $instrumented->enqueue($data);
    
    $snapshot = $metrics->getSnapshot();
    $this->assertArrayHasKey('counters', $snapshot);
}
```

## Current Test Statistics

- **Total Tests**: 99
- **Assertions**: ~310+
- **Skipped**: 4-5 (backend dependencies)
- **Coverage**: Available in `build/coverage/html/`

## Test Categories Summary

| Category | Tests | Focus Area |
|----------|-------|------------|
| Queues | 15 | Redis, Database, Beanstalk, ServiceBus backends |
| Logging | 11 | File/DB handlers, masking, rotation, pruning |
| Retry | 7 | Fixed, exponential, jitter policies |
| Jobs | 6 | Job creation, envelopes, enqueue operations |
| Commands | 5 | CLI command execution and output |
| Execution | 4 | Job lifecycle, completion strategies |
| Callbacks | 3 | Callback chains, filters, conditions |
| Scheduler | 2 | Cron scheduling, dependencies |
| Helpers | 2 | DateTime parsing, requeue logic |
| Metrics | 1 | Metrics collection and export |
| Traits | 1 | Activity trait functionality |

## Continuous Integration

Tests run automatically on:
- **GitHub Actions** on push to `master` and pull requests
- **PHP 8.3** with Redis 7
- Generates coverage reports to Coveralls

See `.github/workflows/php.yml` for CI configuration.

## Troubleshooting

### QueueManager Instance Pollution
**Problem**: Tests fail when run together but pass individually.

**Solution**: Add `QueueManager::reset()` in `setUp()` method:
```php
protected function setUp(): void
{
    parent::setUp();
    QueueManager::reset();
}
```

### Redis Connection Errors
**Problem**: Redis tests fail with connection refused.

**Solution**: 
1. Ensure Redis is running: `redis-server`
2. Check connection: `redis-cli ping`
3. Set environment variables if needed: `REDIS_HOST`, `REDIS_PORT`

### Database Migration Issues
**Problem**: Table not found errors.

**Solution**: Ensure migrations are published and run:
```bash
php spark jobs:publish
php spark migrate -all
```

## Contributing Tests

When adding new features:
1. Write tests in appropriate category folder
2. Ensure tests are isolated (no shared state)
3. Use descriptive test names
4. Add assertions for both success and failure cases
5. Document any special test requirements

Pull requests should maintain or improve test coverage.
