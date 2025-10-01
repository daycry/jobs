<?php

declare(strict_types=1);

namespace Daycry\Jobs\Cronjob;

use CodeIgniter\Exceptions\RuntimeException;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Job;
/**
 * Class Scheduler
 *
 * Handles the registration and management of scheduled jobs.
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
     * Internal method to create and register a job.
     *
     * @param mixed $action
     */
    public function addJob(string $job, mixed $action): Job
    {
        $config = config('Jobs');

        if(!in_array($job, array_keys($config->jobs), true) 
            || !is_subclass_of($config->jobs[$job], Job::class)) {
            throw JobException::forInvalidJob($job);
        }

        $job = new Job( job: $job, payload: $action );
        $this->jobs[] = $job;

        return $job;
    }

    public function getExecutionOrder(): array
    {
        $jobsByName = [];
        $graph      = [];
        $inDegree   = [];

        // Inicializar estructuras
        foreach ($this->jobs as $job) {
            $name = $job->getName();
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
        $queue = array_keys(array_filter($inDegree, fn($deg) => $deg === 0));
        $order = [];

        // Procesar grafo
        while ($queue) {
            $current   = array_shift($queue);
            $order[]   = $jobsByName[$current];

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