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

use CodeIgniter\Exceptions\RuntimeException;
use Daycry\Jobs\Definition\JobBuilder;
use Daycry\Jobs\Definition\JobDefinition;

/**
 * Fluent registry of scheduled jobs for the v3 cron runner.
 *
 * This is the v3 replacement for the v1 {@see \Daycry\Jobs\Cronjob\Scheduler}. Where the v1
 * scheduler held mutable {@see \Daycry\Jobs\Job} instances, the v3 scheduler accumulates
 * {@see JobBuilder} instances registered through {@see define()} and materialises them to
 * immutable {@see JobDefinition} value objects on demand.
 *
 * Usage:
 *   $scheduler->define('command', 'jobs:test')->named('reports')->dailyAt('02:00')->queue('reports');
 */
final class Scheduler
{
    /**
     * @var list<JobBuilder> Registered builders, in declaration order.
     */
    private array $builders = [];

    /**
     * Register a job by handler key and payload, returning the {@see JobBuilder} so the caller
     * can chain frequency/queue/identity helpers (e.g. ->dailyAt('02:00')->queue('reports')).
     */
    public function define(string $handler, mixed $payload = null): JobBuilder
    {
        $builder          = new JobBuilder($handler, $payload);
        $this->builders[] = $builder;

        return $builder;
    }

    /**
     * Materialise every registered builder into an immutable {@see JobDefinition}.
     *
     * @return list<JobDefinition>
     */
    public function getDefinitions(): array
    {
        return array_map(static fn (JobBuilder $builder): JobDefinition => $builder->toDefinition(), $this->builders);
    }

    /**
     * Return the registered definitions in dependency-safe execution order (topological sort by
     * {@see JobDefinition::$dependsOn}). Throws on circular dependencies or unknown dependencies.
     *
     * @return list<JobDefinition>
     */
    public function getExecutionOrder(): array
    {
        /** @var array<string, JobDefinition> $byName */
        $byName = [];
        /** @var array<string, list<string>> $graph */
        $graph = [];
        /** @var array<string, int> $inDegree */
        $inDegree = [];

        foreach ($this->getDefinitions() as $definition) {
            $name            = $this->resolveName($definition);
            $byName[$name]   = $definition;
            $graph[$name]    = [];
            $inDegree[$name] = 0;
        }

        foreach ($byName as $name => $definition) {
            foreach ($definition->dependsOn as $dependency) {
                if (! isset($byName[$dependency])) {
                    throw new RuntimeException("Dependency '{$dependency}' for job '{$name}' does not exist.");
                }
                $graph[$dependency][] = $name;
                $inDegree[$name]++;
            }
        }

        /** @var list<string> $queue */
        $queue = array_keys(array_filter($inDegree, static fn (int $degree): bool => $degree === 0));

        /** @var list<JobDefinition> $order */
        $order = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $order[] = $byName[$current];

            foreach ($graph[$current] as $neighbour) {
                if (--$inDegree[$neighbour] === 0) {
                    $queue[] = $neighbour;
                }
            }
        }

        if (count($order) !== count($byName)) {
            throw new RuntimeException('Circular dependency detected in jobs.');
        }

        return $order;
    }

    /**
     * Remove every registered builder.
     */
    public function clear(): void
    {
        $this->builders = [];
    }

    /**
     * Resolve the topological-sort key for a definition: its explicit name, or a stable fallback
     * derived from the handler when none was set (mirrors the v3 envelope naming).
     */
    private function resolveName(JobDefinition $definition): string
    {
        if ($definition->name !== null && $definition->name !== '') {
            return $definition->name;
        }

        return $definition->handler . ':' . substr(md5(serialize($definition->payload)), 0, 8);
    }
}
