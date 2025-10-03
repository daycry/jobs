<?php

declare(strict_types=1);

use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\DatabaseQueue;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Models\QueueModel;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class DatabaseQueueRemoveJobTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // ensure in-memory or test db is migrated; relying on existing test bootstrap.
    }

    private function enqueueJob(): array
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->setQueue('default');
        $queue = new DatabaseQueue();
        $id = $queue->enqueue($job->toObject());
        return [$queue, $id];
    }

    public function testRemoveJobSuccessPath(): void
    {
        [$queue] = $this->enqueueJob();
        $env = $queue->watch('default');
        $job = Job::fromQueueRecord($env->payload);
        $queue->removeJob($job, false);
        $model = new QueueModel();
        $record = $model->where('identifier', $env->id)->first();
        $this->assertSame('completed', $record->status);
    }

    public function testRemoveJobFailureRecreate(): void
    {
        [$queue] = $this->enqueueJob();
        $env = $queue->watch('default');
        $job = Job::fromQueueRecord($env->payload);
        $queue->removeJob($job, true);
        $model = new QueueModel();
        $record = $model->where('identifier', $env->id)->first();
        $this->assertSame('failed', $record->status);
    }
}
