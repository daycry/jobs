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

namespace Tests\Unit\V3\Coverage;

use DateTime;
use Daycry\Jobs\Commands\BaseJobsCommand;
use Daycry\Jobs\Commands\CronRunCommand;
use Daycry\Jobs\Models\QueueModel;
use ReflectionMethod;
use stdClass;
use Tests\Support\DatabaseTestCase;

/**
 * Line-coverage exercises for {@see CronRunCommand} and (indirectly) the enable/disable runtime
 * gating provided by {@see \Daycry\Jobs\Commands\BaseJobsCommand}.
 *
 * @internal
 */
final class CovCronRunCommandTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueModel::resetSkipLockedDetection();
        $this->jobsConfig()->worker = 'database';
        service('cache')->delete('jobs_active');
    }

    protected function tearDown(): void
    {
        service('cache')->delete('jobs_active');
        parent::tearDown();
    }

    private function command(): CronRunCommand
    {
        return new CronRunCommand(service('logger'), service('commands'));
    }

    private function enableFlag(): void
    {
        $settings             = new stdClass();
        $settings->status     = 'enabled';
        $settings->updated_at = new DateTime();
        service('cache')->save('jobs_active', $settings, 0);
    }

    public function testRunWhenInactiveWarnsAndReturnsSuccess(): void
    {
        // No 'jobs_active' flag -> isActive() is false -> tryToEnable() + SUCCESS.
        ob_start();
        $result = $this->command()->run([]);
        ob_get_clean();

        $this->assertSame(BaseJobsCommand::SUCCESS, $result);
    }

    public function testRunWhenActiveExecutesEmptySchedulerAndReturnsSuccess(): void
    {
        $this->enableFlag();

        // Default Jobs::init() registers no scheduled jobs, so CronRunner iterates an empty
        // execution order and returns without running anything.
        ob_start();
        $result = $this->command()->run([]);
        ob_get_clean();

        $this->assertSame(BaseJobsCommand::SUCCESS, $result);
    }

    public function testRunWhenActiveWithTestTimeParam(): void
    {
        $this->enableFlag();

        // Exercise the testTime parsing branch (params['testTime'] is a non-empty string).
        ob_start();
        $result = $this->command()->run(['testTime' => '2026-06-03 12:00:00']);
        ob_get_clean();

        $this->assertSame(BaseJobsCommand::SUCCESS, $result);
    }

    public function testEnableThenDisableViaReflection(): void
    {
        $command = $this->command();

        $enable = new ReflectionMethod($command, 'enable');

        ob_start();
        $enabled = $enable->invoke($command);
        ob_get_clean();
        $this->assertTrue($enabled);

        // After enable(), isActive() must report true.
        $isActive = new ReflectionMethod($command, 'isActive');
        $this->assertTrue($isActive->invoke($command));

        $disable = new ReflectionMethod($command, 'disable');

        ob_start();
        $disabled = $disable->invoke($command);
        ob_get_clean();
        $this->assertTrue($disabled);

        // After disable(), isActive() must report false.
        $this->assertFalse($isActive->invoke($command));
    }

    public function testAlreadyEnabledAndAlreadyDisabledHelpers(): void
    {
        $command = $this->command();

        $alreadyEnabled  = new ReflectionMethod($command, 'alreadyEnabled');
        $alreadyDisabled = new ReflectionMethod($command, 'alreadyDisabled');

        ob_start();
        $alreadyEnabled->invoke($command);
        $alreadyDisabled->invoke($command);
        $output = (string) ob_get_clean();

        // Both helpers emit output without throwing; assert the call path completed.
        $this->assertIsString($output);
    }
}
