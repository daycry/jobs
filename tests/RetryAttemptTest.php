<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Job;

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
    $delta2 = $afterSecond->getTimestamp() - (new DateTime())->getTimestamp();
    $this->assertTrue($delta2 <= 3605 && $delta2 >= 3590, 'Schedule should be reset to about +1 hour again');
    }
}
