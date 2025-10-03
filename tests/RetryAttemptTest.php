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
final class RetryAttemptTest extends TestCase
{
    public function testAddAttemptIncrementsAndAdjustsSchedule(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('retry_job');
        $now = new DateTimeImmutable('now');
        $job->scheduled(new DateTime($now->format('Y-m-d H:i:s')));
        $original = $job->toObject()->schedule; // DateTime

        $job->addAttempt();
        $this->assertSame(1, $job->getAttempt());
        $afterFirst = $job->toObject()->schedule;
        // Debe ser aproximadamente +1h respecto a 'now'
        $delta1 = $afterFirst->getTimestamp() - (new DateTime())->getTimestamp();
        $this->assertTrue($delta1 <= 3605 && $delta1 >= 3590, 'Schedule should be about +1 hour');

        $job->addAttempt();
        $this->assertSame(2, $job->getAttempt());
        $afterSecond = $job->toObject()->schedule;
        $delta2      = $afterSecond->getTimestamp() - (new DateTime())->getTimestamp();
        $this->assertTrue($delta2 <= 3605 && $delta2 >= 3590, 'Schedule should be reset to about +1 hour again');
    }
}
