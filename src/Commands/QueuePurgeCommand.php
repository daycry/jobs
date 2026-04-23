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

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Daycry\Jobs\Models\QueueModel;

/**
 * Purge completed or failed jobs from the queue table.
 * Essential for maintenance as the queue table grows indefinitely.
 */
class QueuePurgeCommand extends BaseCommand
{
    protected $group       = 'Jobs';
    protected $name        = 'jobs:queue:purge';
    protected $description = 'Purge completed/failed jobs from the queue table';
    protected $usage       = 'jobs:queue:purge [options]';
    protected $arguments   = [];
    protected $options     = [
        '--status'  => 'Status to purge: completed, failed, or all (default: completed)',
        '--queue'   => 'Purge only a specific queue',
        '--before'  => 'Purge jobs created before this date (e.g. "2026-01-01" or "-7 days")',
        '--dry-run' => 'Show count without deleting',
        '--force'   => 'Skip confirmation prompt',
    ];

    public function run(array $params): void
    {
        $status = CLI::getOption('status') ?? 'completed';
        $queue  = CLI::getOption('queue');
        $before = CLI::getOption('before');
        $dryRun = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);
        $force  = CLI::getOption('force') !== null   || array_key_exists('force', $params);

        $validStatuses = ['completed', 'failed', 'all'];
        if (! in_array($status, $validStatuses, true)) {
            CLI::error("Invalid --status value '{$status}'. Use: completed, failed, or all");

            return;
        }

        $queueModel = new QueueModel();

        // Build query
        if ($status !== 'all') {
            $queueModel->where('status', $status);
        } else {
            $queueModel->whereIn('status', ['completed', 'failed']);
        }

        if ($queue) {
            $queueModel->where('queue', $queue);
        }

        if ($before) {
            $timestamp = strtotime($before);
            if ($timestamp === false) {
                CLI::error("Invalid --before date format: '{$before}'");

                return;
            }
            $queueModel->where('created_at <', date('Y-m-d H:i:s', $timestamp));
        }

        $count = $queueModel->countAllResults(false);

        if ($count === 0) {
            CLI::write('No matching jobs found to purge.', 'yellow');

            return;
        }

        $label = $queue ? "in queue '{$queue}'" : 'across all queues';
        CLI::write("Found {$count} {$status} job(s) {$label} to purge.", 'yellow');

        if ($dryRun) {
            CLI::write('[Dry Run] No records were deleted.', 'cyan');

            return;
        }

        if (! $force) {
            $confirm = CLI::prompt('Are you sure you want to delete these records?', ['y', 'n']);
            if ($confirm !== 'y') {
                CLI::write('Purge cancelled.', 'yellow');

                return;
            }
        }

        // Re-build query for deletion (countAllResults with false resets the query partially)
        $deleteModel = new QueueModel();

        if ($status !== 'all') {
            $deleteModel->where('status', $status);
        } else {
            $deleteModel->whereIn('status', ['completed', 'failed']);
        }

        if ($queue) {
            $deleteModel->where('queue', $queue);
        }

        if ($before) {
            $deleteModel->where('created_at <', date('Y-m-d H:i:s', strtotime($before)));
        }

        $deleted = $deleteModel->delete();

        if ($deleted) {
            CLI::write("Successfully purged {$count} job(s).", 'green');
        } else {
            CLI::error('Failed to purge jobs. Check database connection.');
        }
    }
}
