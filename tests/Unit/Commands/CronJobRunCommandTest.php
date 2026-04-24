<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Queues.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

use CodeIgniter\CLI\Commands;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class CronJobRunCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cache = service('cache');
        $cache->save('jobs_active', (object) ['status' => 'enabled', 'updated_at' => new DateTime()], 0);
        config('Jobs')->logPerformance = false;
        config('Jobs')->log            = 'file';
    }

    protected function tearDown(): void
    {
        service('cache')->delete('jobs_active');
        parent::tearDown();
    }

    public function testRunExecutesScheduledJobs(): void
    {
        $scheduler = service('scheduler');
        config('Jobs')->init($scheduler);

        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:run', []);

        $this->assertSame(0, $result);
    }

    public function testRunWithTestTimeOption(): void
    {
        $scheduler = service('scheduler');
        config('Jobs')->init($scheduler);

        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:run', ['testTime' => '2024-01-01 00:00:00']);

        $this->assertSame(0, $result);
    }

    public function testRunWithOnlyOption(): void
    {
        $scheduler = service('scheduler');
        config('Jobs')->init($scheduler);

        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:run', ['only' => 'enabled']);

        $this->assertSame(0, $result);
    }

    public function testRunWhenDisabledReturnsFailure(): void
    {
        service('cache')->delete('jobs_active');

        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:run', []);

        $this->assertSame(1, $result);
    }
}
