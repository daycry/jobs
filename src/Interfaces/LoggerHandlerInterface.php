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

namespace Daycry\Jobs\Interfaces;

use CodeIgniter\I18n\Time;

interface LoggerHandlerInterface
{
    public function handle($level, $message): bool;

    public function setPath(string $name): static;

    public function lastRun(string $name): string|Time;

    /**
     * @return array<int,object>
     */
    public function history(string $name, int $limit = 10): array;
}
