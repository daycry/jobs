<?php

declare(strict_types=1);

namespace Daycry\Jobs\Loggers;

use CodeIgniter\I18n\Time;
use CodeIgniter\Log\Handlers\BaseHandler;
use Daycry\Jobs\Config\Jobs as JobsConfig;

class FileHandler extends BaseHandler
{
    private ?string $path = null;

    private string $name;

    public function __construct(array $config = [])
    {
        $configuration = config('Jobs');
        $this->path = $configuration->filePath;

        if($this->path  !== null && !is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    public function handle($level, $message): bool
    {
        /** @var JobsConfig config */
        $config   = config('Jobs');
        $fileName = $config->filePath . '/' . $this->name . '.json';

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

    public function setPath(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function lastRun(string $name): string|Time
    {
        $fileName = $this->path . '/' . $name . '.json';

        if (! is_dir($this->path) && ! file_exists($fileName)) {
            return '--';
        }

        $logs = \json_decode(\file_get_contents($fileName));

        if (empty($logs)) {
            return '--';
        }

        return Time::parse($logs[0]->start_at);
    }
}