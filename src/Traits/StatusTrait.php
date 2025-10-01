<?php

declare(strict_types=1);

namespace Daycry\Jobs\Traits;

use DateTime;
use Daycry\Jobs\Job;

/**
 * Trait StatusTrait
 *
 * @mixin Job
 */
trait StatusTrait
{
    public function saveRunningFlag(): bool
    {
        $cache = service('cache');

        return $cache->save('job_running', $this->getName(), 0);
    }

    public function isRunning(): bool
    {
        $cache = service('cache');

        if ($cache->get('job_running') === $this->getName()) {
            return true;
        }

        return false;
    }

    public function clearRunningFlag(): bool
    {
        $cache = service('cache');

        return $cache->delete('job_running');
    }
}