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

use CodeIgniter\I18n\Time;
use Config\Services as BaseServices;
use DateTime;
use DateTimeInterface;
use Daycry\Jobs\Cronjob\Scheduler;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Job;

class Services extends BaseServices
{
    public static function scheduler(bool $getShared = true): Scheduler
    {
        if ($getShared) {
            return static::getSharedInstance('scheduler');
        }

        return new Scheduler();
    }

    /**
     * Convenience helper to create and immediately enqueue a Job directly
     * through the configured worker, bypassing the Scheduler (cron) layer.
     *
     * Typical usage:
     *  $id = \Daycry\Jobs\Config\Services::queueJob('command', 'jobs:test', null, function(Job $job) {
     *      $job->named('on_demand')->priority(3)->singleInstance();
     *  });
     *
     * The optional $configure callback receives the mutable Job builder so the
     * caller can chain methods (named(), priority(), scheduled(), etc.) before
     * it is pushed to the underlying queue worker. The Job's source will be
     * automatically marked as 'queue' (handled inside push()).
     *
     * @param string        $job       Handler key (must exist in config('Jobs')->jobs)
     * @param mixed         $payload   Arbitrary payload for the handler
     * @param string|null   $queue     Optional explicit queue name; if null first configured queue is used
     * @param callable|null $configure function(Job $job): void — mutate Job before enqueue
     *
     * @return string Queue identifier returned by the worker implementation
     *
     * @throws JobException If the handler key is invalid
     */
    public static function queueJob(
        string $job,
        mixed $payload = null,
        ?string $queue = null,
        ?callable $configure = null,
        DateTimeInterface|int|string|Time|null $when = null,
    ): string {
        $cfg = config('Jobs');
        if (! isset($cfg->jobs[$job])) {
            throw JobException::forInvalidJob($job);
        }

        $instance = new Job(job: $job, payload: $payload);

        if ($configure) {
            $configure($instance);
        }

        // Normalize scheduling time if provided
        if ($when !== null) {
            $dt = null;
            if ($when instanceof DateTimeInterface) {
                $dt = $when;
            } elseif ($when instanceof Time) {
                $dt = $when->toDateTime();
            } elseif (is_int($when)) {
                $dt = (new DateTime())->modify('+' . $when . ' seconds');
            } elseif (is_string($when)) {
                $dt = new DateTime($when);
            }
            if ($dt) {
                $instance->scheduled($dt);
            }
        }

        // Only set queue if not already configured by $configure callback
        if ($instance->getQueue() === null) {
            $instance->enqueue($queue);
        }

        return (string) $instance->push();
    }
}
