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

use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class LoggingTruncateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $jobs                  = config('Jobs');
        $jobs->log             = 'file';
        $jobs->logPerformance  = true;
        $jobs->maxOutputLength = 50; // for test
        $jobs->filePath        = rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs';
        $this->cleanFile('truncate_job');
    }

    private function cleanFile(string $name): void
    {
        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/' . $name . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function testOutputIsTruncated(): void
    {
        $payload = str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 5); // 130 chars
        $job     = new Job(job: 'closure', payload: static fn () => $payload);
        $job->named('truncate_job');
        (new JobLifecycleCoordinator())->run($job);

        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/truncate_job.json';
        $this->assertFileExists($file);
        $json = json_decode(file_get_contents($file));
        $this->assertIsArray($json);
        $first = $json[0];
        $this->assertLessThan(strlen($payload), strlen($first->output));
        $this->assertStringContainsString('[truncated', $first->output);
    }
}
