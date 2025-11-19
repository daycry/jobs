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
final class JobBasicTest extends TestCase
{
    public function testJobStoresPayloadAndName(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('my_command');

        $this->assertSame('command', $job->getJob());
        $this->assertSame('jobs:test', $job->getPayload());
        $this->assertSame('my_command', $job->getName());
    }
}
