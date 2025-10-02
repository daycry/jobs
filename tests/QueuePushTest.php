<?php
declare(strict_types=1);

use Tests\Support\DatabaseTestCase;
use Tests\Support\TestCase;
use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\DatabaseQueue;
use Daycry\Jobs\Models\QueueModel;

final class QueuePushTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $jobs = config('Jobs');
        $jobs->queues = 'default';
        $jobs->workers = [ 'database' => DatabaseQueue::class ];
        $jobs->worker = 'database';
        // No depender de service('settings'): DatabaseQueue ahora hace fallback automático.
    }

    public function testPushCreatesQueueRecord(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('queue_command')->enqueue('default');

        $id = $job->push();
        $this->assertIsString($id);
        $this->assertSame(32, strlen($id));

        $model = new QueueModel();
        $record = $model->where('identifier', $id)->first();
        $this->assertNotNull($record, 'Queue record not found');
        $this->assertSame('pending', $record->status);
    }
}
