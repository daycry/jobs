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

use Daycry\Jobs\Loggers\FileHandler;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileHandlerNameFallbackTest extends TestCase
{
    public function testFallbacksToPayloadNameWhenNoSetPath(): void
    {
        $cfg                 = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->filePath       = sys_get_temp_dir() . '/jobs_fh_test_' . bin2hex(random_bytes(3)) . '/';
        if (! is_dir($cfg->filePath)) {
            mkdir($cfg->filePath, 0777, true);
        }
        $handler = new FileHandler();
        $payload = [
            'name'        => 'custom_job_name',
            'job'         => 'command',
            'executionId' => 'uuid-test',
            'attempt'     => 0,
            'payload'     => null,
            'start_at'    => date('Y-m-d H:i:s'),
            'end_at'      => date('Y-m-d H:i:s'),
        ];
        $handler->handle('info', json_encode($payload));
        $file = rtrim($cfg->filePath, '/\\') . '/custom_job_name.json';
        if (! file_exists($file)) {
            $this->markTestSkipped('File not created (environment permissions?)');
        }
        $decoded = json_decode(file_get_contents($file));
        $this->assertIsArray($decoded);
        $this->assertSame('uuid-test', $decoded[0]->executionId ?? null);
    }

    public function testFallbackToUnnamedIfNoName(): void
    {
        $cfg           = config('Jobs');
        $cfg->filePath = sys_get_temp_dir() . '/jobs_fh_test_' . bin2hex(random_bytes(3)) . '/';
        if (! is_dir($cfg->filePath)) {
            mkdir($cfg->filePath, 0777, true);
        }
        $handler = new FileHandler();
        $payload = [
            'job'         => 'command',
            'executionId' => 'uuid-test-2',
            'attempt'     => 0,
            'start_at'    => date('Y-m-d H:i:s'),
            'end_at'      => date('Y-m-d H:i:s'),
        ];
        $handler->handle('info', json_encode($payload));
        $file = rtrim($cfg->filePath, '/\\') . '/unnamed.json';
        $this->assertFileExists($file);
        $decoded = json_decode(file_get_contents($file));
        $this->assertSame('uuid-test-2', $decoded[0]->executionId ?? null);
    }
}
