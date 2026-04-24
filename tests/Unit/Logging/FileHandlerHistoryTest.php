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
use Tests\Support\TestCase;

/**
 * @internal
 */
final class FileHandlerHistoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/jobs_fhist_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->tmpDir, 0777, true);
        config('Jobs')->filePath = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temp dir
        $files = glob($this->tmpDir . '*');

        foreach ($files !== false ? $files : [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function makeHandler(string $name): FileHandler
    {
        $handler = new FileHandler();
        $handler->setPath($name);

        return $handler;
    }

    public function testLastRunReturnsDoubleDashWhenNoFile(): void
    {
        $handler = $this->makeHandler('nonexistent_job');
        $result  = $handler->lastRun('nonexistent_job');

        $this->assertSame('--', $result);
    }

    public function testHistoryReturnsEmptyWhenNoFile(): void
    {
        $handler = $this->makeHandler('nonexistent_job2');
        $result  = $handler->history('nonexistent_job2');

        $this->assertSame([], $result);
    }

    public function testLastRunReturnsParsedTimeAfterHandle(): void
    {
        $name    = 'test_job_lr';
        $handler = $this->makeHandler($name);
        $startAt = date('Y-m-d H:i:s');

        $handler->handle('info', json_encode([
            'name'        => $name,
            'job'         => 'command',
            'executionId' => 'exec-1',
            'attempt'     => 1,
            'start_at'    => $startAt,
            'end_at'      => date('Y-m-d H:i:s'),
        ]));

        $result = $handler->lastRun($name);
        $this->assertNotSame('--', $result);
    }

    public function testHistoryReturnsEntriesAfterHandle(): void
    {
        $name    = 'test_job_hist';
        $handler = $this->makeHandler($name);

        // Add two log entries
        for ($i = 1; $i <= 2; $i++) {
            $handler->handle('info', json_encode([
                'name'        => $name,
                'job'         => 'command',
                'executionId' => "exec-{$i}",
                'attempt'     => $i,
                'start_at'    => date('Y-m-d H:i:s'),
                'end_at'      => date('Y-m-d H:i:s'),
            ]));
        }

        $result = $handler->history($name, 10);
        $this->assertCount(2, $result);
        $this->assertNotSame('', (string) ($result[0]->executionId ?? ''));
    }

    public function testHistoryRespectsLimit(): void
    {
        $name    = 'test_job_limit';
        $handler = $this->makeHandler($name);

        for ($i = 1; $i <= 5; $i++) {
            $handler->handle('info', json_encode([
                'name'        => $name,
                'job'         => 'command',
                'executionId' => "exec-{$i}",
                'attempt'     => $i,
                'start_at'    => date('Y-m-d H:i:s'),
                'end_at'      => date('Y-m-d H:i:s'),
            ]));
        }

        $result = $handler->history($name, 3);
        $this->assertCount(3, $result);
    }

    public function testLastRunReturnsDashWhenLogsMissingStartAt(): void
    {
        $name     = 'test_job_nostart';
        $handler  = $this->makeHandler($name);
        $filePath = $this->tmpDir . $name . '.json';

        // Write a file without start_at
        file_put_contents($filePath, json_encode([(object) ['executionId' => 'x']]));

        $result = $handler->lastRun($name);
        $this->assertSame('--', $result);
    }
}
