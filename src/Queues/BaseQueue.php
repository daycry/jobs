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

namespace Daycry\Jobs\Queues;

use DateTime;

abstract class BaseQueue
{
    private int $delay = 0;

    public function calculateDelay(object $data): void
    {
        if (isset($data->schedule)) {
            $now = new DateTime('now');

            $delay = $data->schedule->getTimestamp() - $now->getTimestamp();

            $delay = ($delay > 0) ? $delay : 0;

            $this->setDelay($delay);
        }
    }

    protected function setDelay(int $delay)
    {
        $this->delay = $delay;

        return $this;
    }

    protected function getDelay()
    {
        return $this->delay;
    }
}
