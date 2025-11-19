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
use Tests\Support\TestCase;

/**
 * @internal
 */
final class EnqueueTest extends TestCase
{
    public function testEnqueueAssignsQueue(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        // Forzamos config de colas simulada
        config('Jobs')->queues = 'default';
        $job->enqueue('default');
        $this->assertSame('default', $job->getQueue());
    }
}
