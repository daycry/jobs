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
        $config  = config('Jobs');
        $decoded = json_decode($message, true);
        if (! is_array($decoded)) {
            return true; // ignore malformed
        }
        // Fallback de nombre si no se definió vía setPath
        if (empty($this->name) && ! empty($decoded['name'])) {
            $this->name = (string) $decoded['name'];
        }

        // Pruning eficiente: contar y borrar excedente sin cargar todo
        if ($config->maxLogsPerJob && ! empty($this->name)) {
            $count = $logModel->where('name', $this->name)->countAllResults();
            if ($count >= $config->maxLogsPerJob) {
                // Borrar los más antiguos dejando (maxLogsPerJob - 1) espacio para el nuevo
                $excess = ($count - $config->maxLogsPerJob) + 1;
                if ($excess > 0) {
                    // Obtener IDs antiguos a eliminar
                    $oldIds = $logModel->select('id')
                        ->where('name', $this->name)
                        ->orderBy('id', 'ASC')
                        ->limit($excess)
                        ->findColumn('id');
                    if ($oldIds) {
                        foreach ($oldIds as $oid) {
                            $logModel->delete($oid);
                        }
                    }
                }
            }
        }
        $status = isset($decoded['error']) && $decoded['error'] !== null ? 'ERROR' : 'OK';
        $row    = [
            'name'          => $decoded['name'] ?? null,
            'job'           => $decoded['job'] ?? null,
            'executionId'   => $decoded['executionId'] ?? null,
            'attempt'       => $decoded['attempt'] ?? null,
            'queue'         => $decoded['queue'] ?? null,
            'source'        => $decoded['source'] ?? null,
            'retryStrategy' => $decoded['retryStrategy'] ?? null,
            'payload'       => $decoded['payload'] ?? null,
            'payloadHash'   => $decoded['payloadHash'] ?? null,
            'environment'   => $decoded['environment'] ?? null,
            'output'        => $decoded['output'] ?? null,
            'outputLength'  => $decoded['outputLength'] ?? null,
            'error'         => $decoded['error'] ?? null,
            'status'        => $status,
            'start_at'      => $decoded['start_at'] ?? null,
            'end_at'        => $decoded['end_at'] ?? null,
            'duration'      => $decoded['duration'] ?? null,
            'test_time'     => $decoded['test_time'] ?? null,
            'data'          => json_encode($decoded, JSON_UNESCAPED_UNICODE),
        ];
        $logModel->insert($row);

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

        // Rows already have columns; data JSON retained for full fidelity
        return $logs;
    }
}
