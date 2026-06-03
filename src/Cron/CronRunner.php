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

namespace Daycry\Jobs\Cron;

use CodeIgniter\I18n\Time;
use Cron\CronExpression;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Execution\JobRuntime;
use Daycry\Jobs\Queues\BackendFactory;

/**
 * Drives execution of due cron jobs registered on a v3 {@see Scheduler}.
 *
 * This is the v3 replacement for {@see \Daycry\Jobs\Cronjob\JobRunner}. For every definition in
 * dependency order it evaluates the cron expression and either enqueues the job (when it declares
 * a queue) or runs a single attempt inline through {@see JobRuntime}.
 *
 * Unlike the v1 runner this never sleeps between jobs: the v1 trailing sleep(defaultTimeout) made
 * every cron invocation block for the full timeout window and must not reappear.
 */
final readonly class CronRunner
{
    private Jobs $config;
    private Scheduler $scheduler;
    private JobRuntime $runtime;

    public function __construct(?Scheduler $scheduler = null, ?Jobs $config = null, ?JobRuntime $runtime = null)
    {
        $this->config    = $config ?? config('Jobs');
        $this->scheduler = $scheduler ?? service('scheduler');
        $this->runtime   = $runtime ?? new JobRuntime();
    }

    /**
     * Evaluate and run every due definition in dependency order.
     *
     * @param Time|null $testTime Frozen evaluation time for deterministic scheduling in tests.
     */
    public function run(?Time $testTime = null): void
    {
        $environment = defined('ENVIRONMENT') ? ENVIRONMENT : 'production';
        $timezone    = config('App')->appTimezone;
        $now         = $testTime ?? Time::now($timezone);

        foreach ($this->scheduler->getExecutionOrder() as $definition) {
            if (! $definition->enabled) {
                continue;
            }

            if ($definition->environments !== [] && ! in_array($environment, $definition->environments, true)) {
                continue;
            }

            if (! (new CronExpression($definition->cronExpression))->isDue($now, $timezone)) {
                continue;
            }

            if ($definition->queue !== null) {
                BackendFactory::make($this->config)->enqueue($definition);

                continue;
            }

            $this->runtime->run(
                $definition,
                new JobContext(
                    payload: $definition->payload,
                    name: $definition->name,
                    queue: $definition->queue,
                ),
            );
        }
    }

    /**
     * Expose the underlying scheduler (useful for callers that register definitions after
     * constructing the runner).
     */
    public function scheduler(): Scheduler
    {
        return $this->scheduler;
    }

    /**
     * @return list<JobDefinition>
     */
    public function dueDefinitions(?Time $testTime = null): array
    {
        $timezone = config('App')->appTimezone;
        $now      = $testTime ?? Time::now($timezone);

        return array_values(array_filter(
            $this->scheduler->getExecutionOrder(),
            static fn (JobDefinition $definition): bool => $definition->enabled
                && (new CronExpression($definition->cronExpression))->isDue($now, $timezone),
        ));
    }
}
