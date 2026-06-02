<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\EventHandler;

/**
 * @internal
 */
final class EventHandlerTest extends CIUnitTestCase
{
    public function testDeniesEventNotInAllowlist(): void
    {
        $cfg                = config(Jobs::class) ?? new Jobs();
        $cfg->allowedEvents = [];

        $this->expectException(JobException::class);
        (new EventHandler())->handle(JobContext::fromPayload(['name' => 'user.deleted']));
    }

    public function testTriggersAllowedEvent(): void
    {
        $cfg                = config(Jobs::class) ?? new Jobs();
        $cfg->allowedEvents = ['jobs.test.event'];

        $hit = false;
        Events::on('jobs.test.event', static function () use (&$hit): void { $hit = true; });

        (new EventHandler())->handle(JobContext::fromPayload(['name' => 'jobs.test.event', 'data' => []]));
        $this->assertTrue($hit);
    }
}
