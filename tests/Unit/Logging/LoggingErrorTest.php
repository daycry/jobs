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

use Daycry\Jobs\Execution\JobExecutor;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class LoggingErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $jobs                 = config('Jobs');
        $jobs->log            = 'file';
        $jobs->logPerformance = true;
        $jobs->filePath       = rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs';
        $this->cleanFile('error_job');
    }

    private function cleanFile(string $name): void
    {
        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/' . $name . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function testErrorIsLogged(): void
    {
        $job = new Job(job: 'closure', payload: static function (): void { throw new RuntimeException('Boom'); });
        $job->named('error_job');
        (new JobExecutor())->execute($job);

        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/error_job.json';
        $this->assertFileExists($file);
        $json = json_decode(file_get_contents($file));
        $this->assertIsArray($json);
        $first = $json[0];
        $this->assertSame('error_job', $first->name);
        $this->assertNull($first->output, 'Output should be null when error');
        $this->assertNotNull($first->error, 'Error should be captured');
        $this->assertStringContainsString('Boom', $first->error);
    }
}
