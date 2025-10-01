<?php

declare(strict_types=1);

namespace Daycry\Jobs;

use CodeIgniter\Log\Handlers\BaseHandler;

class DatabaseHandler extends BaseHandler
{
    private $table;
    public function __construct(array $config = [])
    {
        dd("hello");
    }

    public function handle($level, $message): bool
    {
        return true;
    }
}