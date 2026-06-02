<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Commands;

use Config\Database;
use Daycry\Jobs\Commands\QueueReapCommand;
use Daycry\Jobs\Commands\QueueWorkCommand;
use Daycry\Jobs\Models\QueueModel;
use Daycry\Jobs\Queues\Backends\DatabaseBackend;
use Daycry\Jobs\V2\JobDefinition;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class QueueV3CommandsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueModel::resetSkipLockedDetection();
        $this->jobsConfig()->worker = 'database';
    }

    private function workCommand(): QueueWorkCommand
    {
        return new QueueWorkCommand(service('logger'), service('commands'));
    }

    private function reapCommand(): QueueReapCommand
    {
        return new QueueReapCommand(service('logger'), service('commands'));
    }

    public function testWorkerProcessesOneJob(): void
    {
        $backend = new DatabaseBackend();
        $id      = $backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'wq'));

        ob_start();
        $this->workCommand()->run(['queue' => 'wq', 'max' => '1']);
        ob_get_clean();

        $row = (new QueueModel())->where('identifier', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
    }

    public function testReapCommandRecoversStaleLease(): void
    {
        $backend = new DatabaseBackend();
        $backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'rq'));

        $lease = $backend->fetch('rq');
        $this->assertNotNull($lease);

        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        Database::connect($group)->table($table)
            ->where('id', (int) $lease->token)
            ->update(['reserved_at' => '2000-01-01 00:00:00']);

        ob_start();
        $this->reapCommand()->run(['queue' => 'rq']);
        ob_get_clean();

        $row = (new QueueModel())->where('queue', 'rq')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
    }
}
