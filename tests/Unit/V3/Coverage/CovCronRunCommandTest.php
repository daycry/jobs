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

use Daycry\Jobs\Commands\BaseJobsCommand;
use Daycry\Jobs\Commands\CronRunCommand;
use Daycry\Jobs\Models\QueueModel;
use Tests\Support\DatabaseTestCase;

/**
 * Line-coverage exercises for {@see CronRunCommand}.
 *
 * The v3 runner has no global enable/disable gate: the legacy `jobs_active` cache flag and the
 * `jobs:cronjob:enable`/`disable` commands were removed, so the command always evaluates the
 * schedule. Per-job control lives in the definition (`enabled()`/`environments()`).
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
    }

    private function command(): CronRunCommand
    {
        return new CronRunCommand(service('logger'), service('commands'));
    }

    public function testRunEvaluatesScheduleAndReturnsSuccess(): void
    {
        // Default Jobs::init() registers no scheduled jobs, so CronRunner iterates an empty
        // execution order and returns without running anything — and there is no gate to satisfy.
        ob_start();
        $result = $this->command()->run([]);
        ob_get_clean();

        $this->assertSame(BaseJobsCommand::SUCCESS, $result);
    }

    public function testRunWithTestTimeParam(): void
    {
        // Exercise the testTime parsing branch (params['testTime'] is a non-empty string).
        ob_start();
        $result = $this->command()->run(['testTime' => '2026-06-03 12:00:00']);
        ob_get_clean();

        $this->assertSame(BaseJobsCommand::SUCCESS, $result);
    }
}
