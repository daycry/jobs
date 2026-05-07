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

namespace Tests\Unit\V1_1;

use Daycry\Jobs\Loggers\FileHandler;
use Tests\Support\TestCase;

/**
 * v1.1 FileHandler now writes NDJSON (one JSON object per line, oldest-first on disk)
 * and reads transparently from both the new and legacy JSON-array formats.
 *
 * @internal
 */
final class FileHandlerNdjsonTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/jobs_ndjson_test_' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        mkdir($this->tmpDir, 0777, true);

        $cfg                 = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->log            = 'file';
        $cfg->filePath       = $this->tmpDir;
        $cfg->maxLogsPerJob  = 10;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach ((array) glob($this->tmpDir . '*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testHandleAppendsOneLinePerCall(): void
    {
        $handler = new FileHandler();
        $handler->setPath('ndjson_demo');

        for ($i = 1; $i <= 3; $i++) {
            $handler->handle('info', json_encode([
                'name'  => 'ndjson_demo',
                'order' => $i,
            ]));
        }

        $file = $this->tmpDir . 'ndjson_demo.json';
        $this->assertFileExists($file);

        $lines = (array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(3, $lines, 'Each handle() call must produce exactly one NDJSON line.');

        $first = json_decode((string) $lines[0]);
        $this->assertSame(1, $first->order);
        $last = json_decode((string) $lines[2]);
        $this->assertSame(3, $last->order);
    }

    public function testHistoryReturnsNewestFirst(): void
    {
        $handler = new FileHandler();
        $handler->setPath('hist_demo');

        for ($i = 1; $i <= 3; $i++) {
            $handler->handle('info', json_encode([
                'name'  => 'hist_demo',
                'order' => $i,
            ]));
        }

        $entries = $handler->history('hist_demo', 10);
        $this->assertCount(3, $entries);
        $this->assertSame(3, $entries[0]->order, 'Newest entry must come first.');
        $this->assertSame(1, $entries[2]->order, 'Oldest entry must come last.');
    }

    public function testHistoryReadsLegacyJsonArray(): void
    {
        // Write a legacy-formatted file (newest first JSON array, like pre-v1.1).
        $legacy = [
            ['name' => 'legacy', 'order' => 3, 'start_at' => '2025-01-03 00:00:00'],
            ['name' => 'legacy', 'order' => 2, 'start_at' => '2025-01-02 00:00:00'],
            ['name' => 'legacy', 'order' => 1, 'start_at' => '2025-01-01 00:00:00'],
        ];
        file_put_contents($this->tmpDir . 'legacy.json', (string) json_encode($legacy, JSON_PRETTY_PRINT));

        $handler = new FileHandler();
        $entries = $handler->history('legacy', 10);

        $this->assertCount(3, $entries);
        $this->assertSame(3, $entries[0]->order);
        $this->assertSame(1, $entries[2]->order);
    }

    public function testFirstWriteMigratesLegacyFileToNdjson(): void
    {
        $legacy = [
            ['name' => 'mig', 'order' => 2],
            ['name' => 'mig', 'order' => 1],
        ];
        file_put_contents($this->tmpDir . 'mig.json', (string) json_encode($legacy, JSON_PRETTY_PRINT));

        $handler = new FileHandler();
        $handler->setPath('mig');
        $handler->handle('info', json_encode(['name' => 'mig', 'order' => 3]));

        $raw = (string) file_get_contents($this->tmpDir . 'mig.json');
        $this->assertStringStartsWith('{', ltrim($raw), 'Legacy file should have been rewritten as NDJSON.');

        $entries = $handler->history('mig', 10);
        $this->assertCount(3, $entries);
        // After migration the order on disk is oldest→newest; history returns newest-first.
        $this->assertSame(3, $entries[0]->order);
        $this->assertSame(1, $entries[2]->order);
    }

    public function testPruningKeepsOnlyMaxLogsPerJobEntries(): void
    {
        config('Jobs')->maxLogsPerJob = 3;

        $handler = new FileHandler();
        $handler->setPath('prune');

        for ($i = 1; $i <= 7; $i++) {
            $handler->handle('info', json_encode([
                'name'  => 'prune',
                'order' => $i,
            ]));
        }

        $entries = $handler->history('prune', 50);
        $this->assertCount(3, $entries, 'Pruning should leave at most maxLogsPerJob entries.');
        $this->assertSame(7, $entries[0]->order, 'Newest entry must be retained.');
        $this->assertSame(5, $entries[2]->order, 'Three most recent entries must be kept (5,6,7).');
    }
}
