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

use CodeIgniter\CLI\CLI;
use Config\Database;
use Daycry\Jobs\Commands\QueuePurgeCommand;
use Daycry\Jobs\Models\QueueModel;
use ReflectionClass;
use Tests\Support\DatabaseTestCase;

/**
 * Line-coverage exercises for {@see QueuePurgeCommand}: the --status / --queue / --before
 * filter branches, --dry-run, --force, the invalid-status guard and the empty-result branch.
 *
 * @internal
 */
final class CovQueuePurgeCommandTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueModel::resetSkipLockedDetection();
        $this->jobsConfig()->worker = 'database';
        $this->setCliOptions([]);
    }

    protected function tearDown(): void
    {
        $this->setCliOptions([]);
        parent::tearDown();
    }

    /**
     * Pin CLI::$options so QueuePurgeCommand::run() reads our values from CLI::getOption().
     *
     * @param array<string, string> $options
     */
    private function setCliOptions(array $options): void
    {
        $ref  = new ReflectionClass(CLI::class);
        $prop = $ref->getProperty('options');
        $prop->setAccessible(true);
        $prop->setValue(null, $options);
    }

    private function command(): QueuePurgeCommand
    {
        return new QueuePurgeCommand(service('logger'), service('commands'));
    }

    /**
     * Insert a row directly with a chosen status / queue / created_at.
     *
     * @param array<string, mixed> $overrides
     */
    private function insertRow(array $overrides = []): void
    {
        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        $now   = date('Y-m-d H:i:s');

        $data = array_merge([
            'identifier'  => bin2hex(random_bytes(6)),
            'queue'       => 'pq',
            'payload'     => json_encode(['job' => 'command', 'data' => 'jobs:test']),
            'priority'    => 5,
            'schedule'    => $now,
            'status'      => 'completed',
            'max_retries' => 0,
            'attempts'    => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $overrides);

        Database::connect($group)->table($table)->insert($data);
    }

    private function countAll(): int
    {
        return (new QueueModel())->countAllResults();
    }

    private function countStatus(string $status): int
    {
        return (new QueueModel())->where('status', $status)->countAllResults();
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function runPurge(QueuePurgeCommand $command, array $params): string
    {
        ob_start();
        $command->run($params);

        return (string) ob_get_clean();
    }

    public function testInvalidStatusShowsErrorAndDeletesNothing(): void
    {
        $this->insertRow(['status' => 'completed']);
        $this->setCliOptions(['status' => 'bogus']);

        $this->runPurge($this->command(), []);

        // Nothing should have been deleted.
        $this->assertSame(1, $this->countAll());
    }

    public function testNoMatchingJobsBranch(): void
    {
        // Only a pending row exists; default status filter is 'completed'.
        $this->insertRow(['status' => 'pending']);
        $this->setCliOptions([]);

        $this->runPurge($this->command(), []);

        $this->assertSame(1, $this->countAll());
    }

    public function testDryRunReportsButDoesNotDelete(): void
    {
        $this->insertRow(['status' => 'completed']);
        $this->insertRow(['status' => 'completed']);
        $this->setCliOptions([]);

        $this->runPurge($this->command(), ['dry-run' => null]);

        // dry-run keeps everything.
        $this->assertSame(2, $this->countStatus('completed'));
    }

    public function testForcePurgesCompletedJobs(): void
    {
        $this->insertRow(['status' => 'completed']);
        $this->insertRow(['status' => 'completed']);
        $this->insertRow(['status' => 'failed']);
        $this->setCliOptions([]);

        $this->runPurge($this->command(), ['force' => null]);

        // Only completed rows are purged; the failed row remains.
        $this->assertSame(0, $this->countStatus('completed'));
        $this->assertSame(1, $this->countStatus('failed'));
    }

    public function testStatusAllPurgesCompletedAndFailed(): void
    {
        $this->insertRow(['status' => 'completed']);
        $this->insertRow(['status' => 'failed']);
        $this->insertRow(['status' => 'pending']);
        $this->setCliOptions(['status' => 'all']);

        $this->runPurge($this->command(), ['force' => null]);

        // pending survives, completed+failed are gone.
        $this->assertSame(0, $this->countStatus('completed'));
        $this->assertSame(0, $this->countStatus('failed'));
        $this->assertSame(1, $this->countStatus('pending'));
    }

    public function testQueueFilterScopesPurge(): void
    {
        $this->insertRow(['status' => 'completed', 'queue' => 'alpha']);
        $this->insertRow(['status' => 'completed', 'queue' => 'beta']);
        $this->setCliOptions(['status' => 'completed', 'queue' => 'alpha']);

        $this->runPurge($this->command(), ['force' => null]);

        // Only the 'alpha' completed row was purged.
        $this->assertSame(1, $this->countStatus('completed'));
        $remaining = (new QueueModel())->where('status', 'completed')->first();
        $this->assertNotNull($remaining);
        $this->assertSame('beta', $remaining->queue);
    }

    public function testBeforeFilterPurgesOnlyOlderRows(): void
    {
        // One old completed row, one recent completed row.
        $this->insertRow(['status' => 'completed', 'created_at' => '2000-01-01 00:00:00', 'identifier' => 'old']);
        $this->insertRow(['status' => 'completed', 'created_at' => date('Y-m-d H:i:s'), 'identifier' => 'new']);
        $this->setCliOptions(['status' => 'completed', 'before' => '2010-01-01']);

        $this->runPurge($this->command(), ['force' => null]);

        $this->assertSame(1, $this->countStatus('completed'));
        $remaining = (new QueueModel())->where('status', 'completed')->first();
        $this->assertNotNull($remaining);
        $this->assertSame('new', $remaining->identifier);
    }

    public function testInvalidBeforeDateShowsErrorAndDeletesNothing(): void
    {
        $this->insertRow(['status' => 'completed']);
        // strtotime() returns false for this nonsense value, hitting the guard branch.
        $this->setCliOptions(['status' => 'completed', 'before' => 'not-a-real-date-xyz']);

        $this->runPurge($this->command(), ['force' => null]);

        $this->assertSame(1, $this->countStatus('completed'));
    }
}
