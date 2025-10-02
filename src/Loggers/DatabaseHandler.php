<?php

declare(strict_types=1);

namespace Daycry\Jobs\Loggers;

use CodeIgniter\Log\Handlers\BaseHandler;
use Daycry\Jobs\Config\Jobs as JobsConfig;
use Daycry\Jobs\Models\JobsLogModel;

class DatabaseHandler extends BaseHandler
{
    private $table;
    private string $name;
    public function __construct(array $config = []) {}

    public function handle($level, $message): bool
    {
        $logModel = model(JobsLogModel::class);
        /** @var JobsConfig config */
        $config   = config('Jobs');

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

    public function setPath(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}