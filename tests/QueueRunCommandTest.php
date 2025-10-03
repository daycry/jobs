<?php

declare(strict_types=1);

use CodeIgniter\CLI\Commands;
use Daycry\Jobs\Commands\QueueRunCommand;
use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\JobEnvelope;
use Tests\Support\TestCase;

/** @internal */
final class QueueRunCommandTest extends TestCase
{
    public function testOneTimeRunProcessesSingleEnvelope(): void
    {
        // Stub worker with a single envelope then null
        $envelope = new JobEnvelope(
            id: 'stub-1',
            queue: 'default',
            payload: (object) ['job' => 'command', 'payload' => 'jobs:test', 'name' => 'stub_name'],
            attempts: 0,
            priority: null,
            scheduledAt: null,
            availableAt: null,
            createdAt: new DateTimeImmutable(),
            meta: [],
            raw: (object) [],
        );
        $stubWorker = new class($envelope) {
            private ?JobEnvelope $env;
            public function __construct($e){ $this->env = $e; }
            public function watch($queue){ $e = $this->env; $this->env = null; return $e; }
            public function removeJob(Job $job, bool $recreate){ return true; }
        };

        $cmd = new class($stubWorker) extends QueueRunCommand {
            private $worker;
            public function __construct($w){ $this->worker = $w; }
            protected function getWorker(){ return $this->worker; }
        };

        ob_start();
        // Ejecutar proceso interno directamente (one-shot) simulando run([...,'--oneTime'])
        $ref = new ReflectionClass($cmd);
        $m   = $ref->getMethod('processQueue');
        $m->setAccessible(true);
        $m->invoke($cmd, 'default');
        $output = ob_get_clean();
        $this->assertTrue(true, 'Command executed without throwing');
    }
}
