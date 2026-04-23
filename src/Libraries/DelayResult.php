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

namespace Daycry\Jobs\Libraries;

use DateTimeInterface;

/**
 * Value object que encapsula el resultado del cálculo de delay.
 *
 * Propiedades:
 *  - seconds: Retraso en segundos (0 = sin retraso)
 *  - scheduledAt: Timestamp objetivo cuando el job debería ejecutarse (puede ser null si delay=0)
 */
final readonly class DelayResult
{
    public function __construct(
        public int $seconds,
        public ?DateTimeInterface $scheduledAt = null,
    ) {
    }

    /**
     * Factory: calcular delay desde un objeto que puede contener schedule.
     */
    public static function fromSchedule(?DateTimeInterface $schedule): self
    {
        if (!$schedule instanceof DateTimeInterface) {
            return new self(0);
        }

        $now    = time();
        $target = $schedule->getTimestamp();
        $delay  = max(0, $target - $now);

        return new self($delay, $schedule);
    }

    public function isImmediate(): bool
    {
        return $this->seconds === 0;
    }
}
