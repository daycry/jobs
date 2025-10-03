<?php

declare(strict_types=1);

namespace Daycry\Jobs\Interfaces;

use CodeIgniter\I18n\Time;

interface LoggerHandlerInterface
{
    public function handle($level, $message): bool;
    public function setPath(string $name): static;
    public function lastRun(string $name): string|Time;
    /** @return array<int,object> */
    public function history(string $name, int $limit = 10): array;
}
