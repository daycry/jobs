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

use CodeIgniter\I18n\Time;
use Cron\CronExpression;

/**
 * Adds retry/timeout metadata and schedule evaluation helpers to a Job.
 * Provides shouldRun() using cron expression & environment filtering, plus max retries and timeout accessors.
 */
trait ActivityTrait
{
    protected ?int $maxRetries = null;
    protected ?int $timeout    = null;

    public function maxRetries(int $retries): self
    {
        $this->maxRetries = $retries;

        return $this;
    }

    public function timeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function getMaxRetries(): ?int
    {
        return $this->maxRetries ?? null;
    }

    /**
     * Get the timeout (in seconds) for this job.
     */
    public function getTimeout(): ?int
    {
        return $this->timeout ?? null;
    }

    public function shouldRun(?Time $testTime = null): bool
    {
        $this->testTime = $testTime;
        // Are we restricting to environments?
        if (! empty($this->environments) && ! $this->runsInEnvironment($_SERVER['CI_ENVIRONMENT'])) {
            return false;
        }

        $cron = new CronExpression($this->getExpression());

        $testTime = ($testTime) ?: 'now';

        return $cron->isDue($testTime, config('App')->appTimezone);
    }

    /**
     * Returns the date this was last ran.
     *
     * @return string|Time
     */
    public function lastRun()
    {
        if (config('Jobs')->logPerformance === false) {
            return '--';
        }

        $name = ($this->name) ?: $this->getName();

        $this->setHandler();

        return $this->handler->lastRun($name);
    }

    /**
     * Returns the last run time as a Time instance or null when not available.
     * Non-breaking addition alongside lastRun() method that can return string "--" when performance logging disabled.
     */
    public function getLastRunTime(): ?Time
    {
        if ($this->config->logPerformance === false) {
            return null;
        }
        $val = $this->lastRun();

        return $val instanceof Time ? $val : null;
    }
}
