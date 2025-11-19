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
use Daycry\Jobs\Queues\BeanstalkQueue;
use Pheanstalk\Pheanstalk;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class BeanstalkQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config('Jobs')->logPerformance = false;
        config('Jobs')->log            = 'file';
    }

    public function testEnqueueReturnsStringIdOrSkips(): void
    {
        if (! class_exists(Pheanstalk::class)) {
            $this->markTestSkipped('pheanstalk library not available');
        }
        // Connectivity probe (non-blocking)
        $host   = config('Jobs')->beanstalk['host'] ?? '127.0.0.1';
        $port   = (int) (config('Jobs')->beanstalk['port'] ?? 11300);
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (! $socket) {
            $this->markTestSkipped('Beanstalkd not reachable: ' . $errstr);
        } else {
            fclose($socket);
        }

        $queue = new BeanstalkQueue();
        $job   = new Job(job: 'command', payload: 'jobs:test');
        $job->named('beanstalk_enqueue')->setQueue('bean_default');
        $obj        = $job->toObject();
        $obj->queue = 'bean_default';
        $id         = $queue->enqueue($obj);
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
        $this->assertMatchesRegularExpression('/^\d+$/', $id, 'Beanstalk ID should be numeric string');
    }
}
