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

namespace Daycry\Jobs\Cronjob;

use CodeIgniter\Exceptions\RuntimeException;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Job;

/**
 * Registers and manages scheduled Job definitions prior to execution.
 * Supports different job creation helpers (command, shell, closure, event, url)
 * and provides a topological ordering based on declared dependencies.
 */
class Scheduler
{
    /**
     * @var list<Job> List of scheduled jobs
     */
    private array $jobs = [];

    /**
     * Returns the created Tasks.
     *
     * @return list<Job>
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /**
     * Removes all scheduled jobs.
     */
    public function clearJobs(): void
    {
        $this->jobs = [];
    }

    /**
     * Find a task by its name.
     */
    public function findJobByName(string $name): ?Job
    {
        foreach ($this->jobs as $job) {
            if ($job->getName() === $name) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Register a command job (CI command string).
     */
    public function command(mixed $action): Job
    {
        return $this->addJob('command', $action);
    }

    /**
     * Register a shell job.
     */
    public function shell(string $command): Job
    {
        return $this->addJob('shell', $command);
    }

    /**
     * Register a closure job.
     */
    public function closure(callable $closure): Job
    {
        return $this->addJob('closure', $closure);
    }

    /**
     * Register an event job (payload array: name + optional data).
     */
    public function event(...$payload): Job
    {
        return $this->addJob('event', $payload);
    }

    /**
     * Register a URL job (payload with method, url, options).
     */
    public function url(...$payload): Job
    {
        return $this->addJob('url', $payload);
    }

    /**
     * Internal method to create and register a job.
     */
    public function addJob(string $job, mixed $action): Job
    {
        $config = config('Jobs');

        if (! in_array($job, array_keys($config->jobs), true)
            || ! is_subclass_of($config->jobs[$job], Job::class)) {
            throw JobException::forInvalidJob($job);
        }

        $job          = new Job(job: $job, payload: $action);
        $this->jobs[] = $job;

        return $job;
    }

    /**
     * Returns jobs in dependency-safe execution order (topological sort).
     * Throws RuntimeException on circular dependencies or missing nodes.
     *
     * @return list<Job>
     */
    public function getExecutionOrder(): array
    {
        $jobsByName = [];
        $graph      = [];
        $inDegree   = [];

        // Inicializar estructuras
        foreach ($this->jobs as $job) {
            $name              = $job->getName();
            $jobsByName[$name] = $job;
            $graph[$name]      = [];
            $inDegree[$name]   = 0;
        }

        // Construir grafo y grados de entrada
        foreach ($this->jobs as $job) {
            foreach ($job->getDependsOn() ?? [] as $dep) {
                if (! isset($jobsByName[$dep])) {
                    throw new RuntimeException("Dependency '{$dep}' for job '{$job->getName()}' does not exist.");
                }
                $graph[$dep][] = $job->getName();
                $inDegree[$job->getName()]++;
            }
        }

        // Inicializar cola con los nodos sin dependencias
        $queue = array_keys(array_filter($inDegree, static fn ($deg) => $deg === 0));
        $order = [];

        // Procesar grafo
        while ($queue) {
            $current = array_shift($queue);
            $order[] = $jobsByName[$current];

            foreach ($graph[$current] as $neighbor) {
                if (--$inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        if (count($order) !== count($jobsByName)) {
            throw new RuntimeException('Circular dependency detected in jobs.');
        }

        return $order;
    }
}
