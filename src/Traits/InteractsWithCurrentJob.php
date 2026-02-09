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

namespace Daycry\Jobs\Traits;

use Daycry\Jobs\Job;

trait InteractsWithCurrentJob
{
    protected ?Job $currentJob = null;

    public function beforeRun(Job $job): Job
    {
        $this->currentJob = $job;

        return $job;
    }

    public function afterRun(Job $job): Job
    {
        $this->currentJob = null;

        return $job;
    }
}
