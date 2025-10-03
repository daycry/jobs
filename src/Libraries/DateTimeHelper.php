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

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class DateTimeHelper
{
    /**
     * Parse a mixed date/time input into a \DateTimeImmutable or null.
     * Accepts: DateTimeInterface (cloned), numeric timestamp, string parseable by DateTime,
     * or null. Returns null on failure.
     */
    public static function parseImmutable(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_numeric($value)) {
            try {
                return (new DateTimeImmutable())->setTimestamp((int) $value);
            } catch (Throwable) {
                return null;
            }
        }
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Convenience: now() wrapper for consistency and easier test stubbing later.
     */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
