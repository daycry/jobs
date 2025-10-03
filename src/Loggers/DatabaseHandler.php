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

namespace Daycry\Jobs\Loggers;

use CodeIgniter\I18n\Time;
use CodeIgniter\Log\Handlers\BaseHandler;
use Daycry\Jobs\Config\Jobs as JobsConfig;
use Daycry\Jobs\Models\JobsLogModel;

/**
 * Database-backed job execution history handler.
 * Persists each execution row into jobs log table defined by JobsLogModel.
 * Enforces maxLogsPerJob by deleting oldest rows beyond the limit.
 */
class DatabaseHandler extends BaseHandler
{
    private $table;
    private string $name;

    public function __construct(array $config = [])
    {
    }

    public function handle($level, $message): bool
    {
        $logModel = model(JobsLogModel::class);
        /** @var JobsConfig config */
        $config = config('Jobs');

        if ($config->maxLogsPerJob) {
            $logs = $logModel->where('name', $this->name)->findAll();
            // Make sure we have room for one more
            if ((is_countable($logs) ? count($logs) : 0) >= $config->maxLogsPerJob) {
                $forDelete = count($logs) - $config->maxLogsPerJob;

                for ($i = 0; $forDelete >= $i; $i++) {
                    $logModel->delete($logs[$i]->id);
                }
            }
        }

        $logModel->insert(json_decode($message));

        return true;
    }

    public function lastRun(string $name): string|Time
    {
        $logModel = model(JobsLogModel::class);
        $log      = $logModel->where('name', $name)->orderBy('id', 'DESC')->first();

        if (empty($log)) {
            return '--';
        }

        return Time::parse($log->start_at);
    }

    public function setPath(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Returns an array of recent executions for a job from database.
     *
     * @return array<int, object>
     */
    public function history(string $name, int $limit = 10): array
    {
        $logModel = model(JobsLogModel::class);
        $logs     = $logModel->where('name', $name)->orderBy('id', 'DESC')->limit($limit)->find();
        if (! is_array($logs)) {
            return [];
        }

        return $logs;
    }
}
