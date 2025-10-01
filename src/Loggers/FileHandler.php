<?php

declare(strict_types=1);

namespace Daycry\Jobs;

use CodeIgniter\Log\Handlers\BaseHandler;

class FileHandler extends BaseHandler
{
    private ?string $path = null;
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
        return true;
    }
}