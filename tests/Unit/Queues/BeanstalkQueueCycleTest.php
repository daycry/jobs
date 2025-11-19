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

use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\Queues\BeanstalkQueue;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RequeueHelper;
use Pheanstalk\Pheanstalk;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class BeanstalkQueueCycleTest extends TestCase
{
    public function testFullCycleWithRequeue(): void
    {
        if (! class_exists(Pheanstalk::class)) {
            $this->markTestSkipped('pheanstalk not installed');
        }
        $cfg    = config('Jobs');
        $host   = $cfg->beanstalk['host'] ?? '127.0.0.1';
        $port   = (int) ($cfg->beanstalk['port'] ?? 11300);
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (! $socket) {
            $this->markTestSkipped('beanstalkd not reachable: ' . $errstr);
        } else {
            fclose($socket);
        }

        $cfg->worker = 'beanstalk';
        if (! str_contains((string) $cfg->queues, 'bean_default')) {
            $cfg->queues .= ',bean_default';
        }

        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('bean_cycle')->setQueue('bean_default');
        // Ensure QueueManager picks up updated default worker before pushing
        QueueManager::reset();
        $id1 = $job->push();
        $this->assertIsString($id1);

        $worker   = new BeanstalkQueue();
        $reserved = $worker->watch('bean_default');
        $this->assertNotNull($reserved, 'Should reserve job');
        $this->assertTrue(isset($reserved->id));

        // Simulate failed execution cycle
        $helper   = new RequeueHelper();
        $envelope = JobEnvelope::fromJob($job, []);
        $helper->finalize($job, $envelope, static function ($j, $recreate) use ($worker): void {
            $worker->removeJob($j, $recreate);
        }, false);
        $this->assertSame(1, $job->getAttempt());

        $reserved2 = $worker->watch('bean_default');
        $this->assertNotNull($reserved2, 'Should reserve requeued job');
        $this->assertNotSame($reserved->id, $reserved2->id);
    }
}
