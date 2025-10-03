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
use Daycry\Jobs\Cronjob\Scheduler;

class Services extends BaseServices
{
    public static function scheduler(bool $getShared = true): Scheduler
    {
        if ($getShared) {
            return static::getSharedInstance('scheduler');
        }

        return new Scheduler();
    }
}
