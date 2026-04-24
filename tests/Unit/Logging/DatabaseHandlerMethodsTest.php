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

use Daycry\Jobs\Loggers\DatabaseHandler;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class DatabaseHandlerMethodsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config('Jobs')->log            = 'database';
        config('Jobs')->logPerformance = true;
    }

    private function insertLogRow(string $name = 'db_handler_test'): void
    {
        $handler = new DatabaseHandler();
        $handler->setPath($name);
        $handler->handle('info', json_encode([
            'name'        => $name,
            'job'         => 'command',
            'executionId' => 'exec-' . bin2hex(random_bytes(4)),
            'attempt'     => 1,
            'queue'       => 'default',
            'source'      => 'cron',
            'start_at'    => date('Y-m-d H:i:s'),
            'end_at'      => date('Y-m-d H:i:s'),
        ]));
    }

    public function testSetPathReturnsSelf(): void
    {
        $handler  = new DatabaseHandler();
        $returned = $handler->setPath('myjob');
        $this->assertSame($handler, $returned);
    }

    public function testHandleIgnoresMalformedJson(): void
    {
        $handler = new DatabaseHandler();
        $result  = $handler->handle('info', 'not valid json at all');
        $this->assertTrue($result);
    }

    public function testLastRunReturnsDoubleDashWhenNoHistory(): void
    {
        $handler = new DatabaseHandler();
        $result  = $handler->lastRun('no_such_job_xyz_' . bin2hex(random_bytes(4)));
        $this->assertSame('--', $result);
    }

    public function testLastRunReturnsParsedTimeAfterInsert(): void
    {
        $name = 'lrtest_' . bin2hex(random_bytes(4));
        $this->insertLogRow($name);

        $handler = new DatabaseHandler();
        $result  = $handler->lastRun($name);
        $this->assertNotSame('--', $result);
    }

    public function testHistoryReturnsEmptyForNonExistentJob(): void
    {
        $handler = new DatabaseHandler();
        $result  = $handler->history('no_such_job_' . bin2hex(random_bytes(4)));
        $this->assertSame([], $result);
    }

    public function testHistoryReturnsRowsAfterInsert(): void
    {
        $name = 'histtest_' . bin2hex(random_bytes(4));
        $this->insertLogRow($name);

        $handler = new DatabaseHandler();
        $result  = $handler->history($name, 10);
        $this->assertNotEmpty($result);
        $this->assertNotNull($result[0]->id ?? null);
    }

    public function testHandleWithNoNameFallsBackToDecodedName(): void
    {
        // Handler without setPath called - should use 'name' from message
        $handler = new DatabaseHandler();
        $name    = 'fallback_' . bin2hex(random_bytes(4));
        $handler->handle('info', json_encode([
            'name'        => $name,
            'job'         => 'command',
            'executionId' => 'exec-fallback',
            'attempt'     => 1,
            'start_at'    => date('Y-m-d H:i:s'),
            'end_at'      => date('Y-m-d H:i:s'),
        ]));
        $this->assertNotSame('--', $handler->lastRun($name));
    }

    public function testHandleWithErrorStatusSetsErrorStatus(): void
    {
        $name    = 'errtest_' . bin2hex(random_bytes(4));
        $handler = new DatabaseHandler();
        $handler->setPath($name);
        $result = $handler->handle('error', json_encode([
            'name'        => $name,
            'job'         => 'command',
            'executionId' => 'exec-err',
            'attempt'     => 1,
            'error'       => 'Something failed',
            'start_at'    => date('Y-m-d H:i:s'),
            'end_at'      => date('Y-m-d H:i:s'),
        ]));
        $this->assertTrue($result);

        $logs = $handler->history($name, 1);
        $this->assertNotEmpty($logs);
        $this->assertSame('ERROR', $logs[0]->status);
    }

    public function testMaxLogsPerJobPruning(): void
    {
        // Set maxLogsPerJob to 3 so pruning kicks in on the 4th insert
        $cfg                = config('Jobs');
        $oldMax             = $cfg->maxLogsPerJob;
        $cfg->maxLogsPerJob = 3;

        $name    = 'prune_' . bin2hex(random_bytes(4));
        $handler = new DatabaseHandler();
        $handler->setPath($name);

        for ($i = 0; $i < 5; $i++) {
            $handler->handle('info', json_encode([
                'name'        => $name,
                'job'         => 'command',
                'executionId' => 'exec-' . $i,
                'attempt'     => 1,
                'start_at'    => date('Y-m-d H:i:s'),
                'end_at'      => date('Y-m-d H:i:s'),
            ]));
        }

        $cfg->maxLogsPerJob = $oldMax;

        $logs = $handler->history($name, 100);
        $this->assertLessThanOrEqual(3, count($logs));
    }
}
