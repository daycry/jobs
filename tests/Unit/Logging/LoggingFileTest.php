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
final class LoggingFileTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $jobs                 = config('Jobs');
        $jobs->log            = 'file';
        $jobs->logPerformance = true; // habilitar logging
        // Alinear ruta esperada con tests (WRITEPATH/logs)
        $jobs->filePath = rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs';
        $this->logDir   = rtrim($jobs->filePath, DIRECTORY_SEPARATOR);
        if (! is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
        // Limpiar archivos previos del job de prueba
        $target = $this->logDir . '/log_closure.json';
        if (file_exists($target)) {
            unlink($target);
        }
    }

    public function testFileLogIsCreatedAndContainsExpectedKeys(): void
    {
        $job = new Job(job: 'closure', payload: static fn () => 'OUT');
        $job->named('log_closure');
        $coordinator = new JobLifecycleCoordinator();
        $result      = $coordinator->run($job)->finalResult; // ExecutionResult
        $this->assertTrue($result->success);

        $file = $this->logDir . '/log_closure.json';
        $this->assertFileExists($file, 'Log file not created');
        $json = json_decode(file_get_contents($file));
        $this->assertIsArray($json);
        $this->assertNotEmpty($json);
        $first = $json[0];
        $this->assertSame('log_closure', $first->name);
        $this->assertSame('closure', $first->job);
        $this->assertObjectHasProperty('start_at', $first);
        $this->assertObjectHasProperty('end_at', $first);
        $this->assertObjectHasProperty('duration', $first);
        $this->assertSame('OUT', $first->output);
        $this->assertNull($first->error);
    }
}
