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
use Throwable;

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
        // Are we restricting to environments?
        if (! empty($this->environments) && ! $this->inEnvironment($_SERVER['CI_ENVIRONMENT'] ?? 'production')) {
            return false;
        }

        $cron = new CronExpression($this->getExpression());

        $testTime = ($testTime) ?: 'now';

        return $cron->isDue($testTime, config('App')->appTimezone);
    }

    /**
     * Returns the date this was last ran, using the configured logger handler.
     */
    public function lastRun(): string|Time
    {
        $config = config('Jobs');

        if ($config->logPerformance === false) {
            return '--';
        }

        $name = ($this->name ?? '') ?: $this->getName();

        $handler = $this->resolveLoggerHandler($config);
        if ($handler === null) {
            return '--';
        }

        return $handler->lastRun($name);
    }

    /**
     * Returns the last run time as a Time instance or null when not available.
     */
    public function getLastRunTime(): ?Time
    {
        $val = $this->lastRun();

        return $val instanceof Time ? $val : null;
    }

    /**
     * Resolve the configured logger handler instance (file or database).
     */
    private function resolveLoggerHandler(object $config): ?object
    {
        if (empty($config->log) || ! isset($config->loggers[$config->log])) {
            return null;
        }

        $class = $config->loggers[$config->log];

        if (! class_exists($class)) {
            return null;
        }

        try {
            $handler = new $class();
        } catch (Throwable) {
            return null;
        }

        return method_exists($handler, 'lastRun') ? $handler : null;
    }
}
