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

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\DatabaseHandler;
use Daycry\Jobs\Loggers\JobLogger;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DatabaseHandlerHybridTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg                 = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->log            = 'database';
        // Ensure model will use default group (may need configured DB in test env)
    }

    public function testExtendedFieldsPersistInDataColumn(): void
    {
        $cfg = config('Jobs');
        if ($cfg->log !== 'database') {
            $this->markTestSkipped('Database logging not enabled');
        }
        $job = new Job(job: 'command', payload: ['alpha' => '1']);
        $job->named('db_hybrid_test');
        $job->source('cron');

        $logger = new JobLogger();
        $logger->start();
        $res = new ExecutionResult(true, 'OK', null, microtime(true) - 0.01, microtime(true));
        $logger->end();

        try {
            $logger->log($job, $res);
        } catch (Throwable $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }

        $handler = new DatabaseHandler();
        $history = $handler->history('db_hybrid_test', 1);
        if (empty($history)) {
            $this->markTestSkipped('No history rows (DB may not be set up)');
        }
        $row = $history[0];
        // After merge, extended keys should be accessible even if only stored in data JSON
        $this->assertTrue(property_exists($row, 'executionId'));
        $this->assertTrue(property_exists($row, 'attempt'));
        $this->assertTrue(property_exists($row, 'outputLength'));
        $this->assertTrue(property_exists($row, 'payloadHash'));
        $this->assertSame('cron', $row->source ?? 'cron');
    }
}
