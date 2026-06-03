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

namespace Daycry\Jobs\Config;

use Config\Services as BaseServices;
use Daycry\Jobs\Cron\Scheduler;

class Services extends BaseServices
{
    /**
     * Shared v3 cron {@see Scheduler}. The cron runner registers definitions on it via
     * Config\Jobs::init() before evaluating which are due.
     */
    public static function scheduler(bool $getShared = true): Scheduler
    {
        if ($getShared) {
            $shared = static::getSharedInstance('scheduler');
            if ($shared instanceof Scheduler) {
                return $shared;
            }
        }

        return new Scheduler();
    }
}
