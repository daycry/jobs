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

/**
 * Provides enable/disable flag and a coarse single-instance running guard via cache.
 *
 * @mixin Job
 */
trait StatusTrait
{
    protected bool $enabled = true;

    public function saveRunningFlag(): bool
    {
        $cache = service('cache');

        return $cache->save('job_running', $this->getName(), 0);
    }

    public function isRunning(): bool
    {
        $cache = service('cache');

        return (bool) ($cache->get('job_running') === $this->getName());
    }

    public function clearRunningFlag(): bool
    {
        $cache = service('cache');

        return $cache->delete('job_running');
    }

    public function disable(): self
    {
        $this->enabled = false;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
