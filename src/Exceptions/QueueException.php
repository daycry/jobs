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

namespace Daycry\Jobs\Exceptions;

use CodeIgniter\Exceptions\RuntimeException;

/**
 * Queue-related configuration and runtime exceptions.
 * Named constructors wrap localization lookups where applicable.
 */
class QueueException extends RuntimeException
{
    public static function forInvalidWorker(string $worker)
    {
        return new self(lang('Job.invalidWorker', [$worker]));
    }

    public static function forInvalidQueue(string $queue)
    {
        return new self(lang('Job.invalidQueue', [$queue]));
    }

    public static function forInvalidConnection(string $error)
    {
        return new self($error);
    }
}
