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

/**
 * Normalized priority levels.
 *
 * Backends que soporten prioridad numérica mapean:
 *  - LOW    = 10
 *  - MEDIUM = 5 (default)
 *  - HIGH   = 1 (menor número = mayor prioridad en Beanstalk)
 *
 * Backends sin soporte (Redis, Database, ServiceBus) ignoran.
 */
enum Priority: int
{
    case LOW    = 10;
    case MEDIUM = 5;
    case HIGH   = 1;

    /**
     * Parse desde valor simbólico o numérico.
     */
    public static function parse(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return match (strtoupper($value)) {
                'LOW'    => self::LOW,
                'MEDIUM' => self::MEDIUM,
                'HIGH'   => self::HIGH,
                default  => null,
            };
        }

        if (is_int($value)) {
            return self::tryFrom($value);
        }

        return null;
    }

    /**
     * Obtiene el valor numérico (para backends como Beanstalk).
     */
    public function toNumeric(): int
    {
        return $this->value;
    }
}
