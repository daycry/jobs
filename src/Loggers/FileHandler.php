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
use Daycry\Jobs\Interfaces\LoggerHandlerInterface;

/**
 * File-based job execution history handler.
 * Stores a JSON array per job (<name>.json) with newest entries first.
 * Enforces maxLogsPerJob by trimming oldest entries.
 */
class FileHandler extends BaseHandler implements LoggerHandlerInterface
{
    private ?string $path = null;
    private string $name;

    public function __construct(array $config = [])
    {
        $configuration = config('Jobs');
        $base          = rtrim($configuration->filePath, '/\\');
        $this->path    = $base;
        if ($this->path !== null && ! is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    /**
     * Persist a structured log entry JSON string for the current job name.
     *
     * @param mixed $level
     * @param mixed $message
     */
    public function handle($level, $message): bool
    {
        /** @var JobsConfig config */
        $config = config('Jobs');
        // Intentar deducir nombre si no fue establecido aún vía setPath()
        if (empty($this->name)) {
            $decoded = json_decode($message, true);
            if (is_array($decoded) && ! empty($decoded['name'])) {
                $this->name = (string) $decoded['name'];
            }
        }
        if (empty($this->name)) {
            $this->name = 'unnamed'; // fallback definitivo
        }
        // Sanitizar nombre para uso de archivo (sin espacios raros / separadores peligrosos)
        $safeName = $this->sanitizeName($this->name);
        $fileName = rtrim($config->filePath, '/\\') . '/' . $safeName . '.json';

        if (file_exists($fileName)) {
            $logs = \json_decode(\file_get_contents($fileName));
        } else {
            $logs = [];
        }

        // Make sure we have room for one more
        if ((is_countable($logs) ? count($logs) : 0) >= $config->maxLogsPerJob) {
            array_pop($logs);
        }

        // Add the log to the top of the array
        array_unshift($logs, json_decode($message));

        file_put_contents(
            $fileName,
            json_encode(
                $logs,
                JSON_PRETTY_PRINT,
            ),
        );

        return true;
    }

    public function setPath(string $name): static
    {
        // Guardar el nombre crudo; la sanitización se hace al persistir
        $this->name = $name;

        return $this;
    }

    public function lastRun(string $name): string|Time
    {
        $safeName = $this->sanitizeName($name);
        $fileName = $this->path . '/' . $safeName . '.json';
        if (! file_exists($fileName)) {
            return '--';
        }
        $raw  = @file_get_contents($fileName);
        $logs = $raw ? json_decode($raw) : [];
        if (! is_array($logs) || empty($logs) || ! isset($logs[0]->start_at)) {
            return '--';
        }

        return Time::parse($logs[0]->start_at);
    }

    /**
     * Returns an array of recent executions for a job.
     * Each element is a stdClass decoded from stored JSON (already contains
     * name, job, payload, environment, start_at, end_at, duration, output, error, test_time).
     * The file stores newest first, so we slice directly.
     *
     * @return array<int, object>
     */
    public function history(string $name, int $limit = 10): array
    {
        $safeName = $this->sanitizeName($name);
        $fileName = $this->path . '/' . $safeName . '.json';
        if (! file_exists($fileName)) {
            return [];
        }
        $logs = json_decode(file_get_contents($fileName));
        if (! is_array($logs)) {
            return [];
        }

        return array_slice($logs, 0, $limit);
    }

    /**
     * Sanitize job name for safe filesystem usage.
     */
    private function sanitizeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'unnamed';
    }
}
