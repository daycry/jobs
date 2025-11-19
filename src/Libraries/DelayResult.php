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
        if ($schedule === null) {
            return new self(0, null);
        }

        $now    = time();
        $target = $schedule->getTimestamp();
        $delay  = $target - $now;

        // Enforce minimum 1 second delay if target is in the future but rounding yields 0
        if ($delay > 0 && $delay < 1) {
            $delay = 1;
        }

        // Force deterministic promotion: if explicit schedule provided but delta=0, set 1 second
        if ($delay === 0) {
            $delay = 1;
        }

        $delay = max(0, $delay);

        return new self($delay, $schedule);
    }

    public function isImmediate(): bool
    {
        return $this->seconds === 0;
    }
}
