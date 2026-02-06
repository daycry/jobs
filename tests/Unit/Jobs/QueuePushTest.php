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
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\Models\QueueModel;
use Daycry\Jobs\Queues\DatabaseQueue;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class QueuePushTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueManager::reset();
        $jobs          = config('Jobs');
        $jobs->queues  = 'default';
        $jobs->workers = ['database' => DatabaseQueue::class];
        $jobs->worker  = 'database';
        // No depender de service('settings'): DatabaseQueue ahora hace fallback automático.
    }

    public function testPushCreatesQueueRecord(): void
    {
        // Sanity: el comando de prueba debe estar registrado
        $commands = new Commands();
        ob_start();
        $commands->run('jobs:test', ['pre']);
        $cmdOut = ob_get_clean();
        $this->assertIsString($cmdOut);
        $this->assertStringContainsString('Commands can output text.', $cmdOut);

        $job = new Job(job: 'command', payload: 'jobs:test'); // payload conserva 'jobs:test'
        $job->named('queue_command')->setQueue('default');

        $id = $job->push();
        $this->assertIsString($id);
        $this->assertSame(36, strlen($id), 'DatabaseQueue uses random_string alnum 32');

        $model  = new QueueModel();
        $record = $model->where('identifier', $id)->first();
        $this->assertNotNull($record, 'Queue record not found');
        $this->assertSame('pending', $record->status);
    }
}
