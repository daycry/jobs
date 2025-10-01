<?php

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