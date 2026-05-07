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

namespace Daycry\Jobs\V2;

use DateTimeImmutable;
use DateTimeInterface;
use Daycry\Jobs\Job;
use ReflectionException;
use ReflectionProperty;

/**
 * Immutable description of a job at definition time.
 *
 * Compared to the v1 mutable {@see \Daycry\Jobs\Job} builder, JobDefinition is a value
 * object: every withXxx() helper returns a new instance, so the same definition can be
 * shared across enqueue sites without spooky action at a distance.
 *
 * The v2 design splits the responsibilities that previously lived on Job into three
 * objects:
 *  - JobDefinition: what the job IS (handler key, payload, scheduling, retry policy).
 *  - JobEnvelope (existing v1 class, reused): how the definition travels through queues.
 *  - JobRuntime (future): the in-flight, mutable state during execution (attempts, output).
 *
 * Adoption is gradual: callers can keep using the v1 Job builder; v2 components accept
 * either input via {@see fromLegacyJob()} until the legacy API is removed in a future
 * major release.
 */
final class JobDefinition
{
    /**
     * @param string                 $handler        Handler key (must exist in Config\Jobs::$jobs).
     * @param mixed                  $payload        Arbitrary payload passed to the handler.
     * @param string|null            $name           Friendly name for logs/metrics; defaults to handler:hash(payload).
     * @param string|null            $queue          Optional explicit queue; null means "use first configured queue".
     * @param int                    $priority       Higher = sooner (backend-dependent).
     * @param int|null               $maxRetries     Null = no retries; integers = retry up to N times before DLQ.
     * @param int|null               $timeout        Per-attempt soft timeout in seconds; null = use config defaultTimeout.
     * @param DateTimeImmutable|null $scheduledAt    Earliest run time (UTC); null = run as soon as possible.
     * @param bool                   $singleInstance Lock with a runtime cache flag to prevent concurrent runs.
     * @param list<string>           $environments   Restrict execution to these CI4 environments; empty = no restriction.
     * @param list<string>           $dependsOn      Job names that must succeed first within the same scheduler run.
     * @param string                 $cronExpression Cron schedule used by Scheduler; defaults to every minute.
     * @param array<string, mixed>   $meta           Free-form metadata propagated to the envelope.
     */
    public function __construct(
        public readonly string $handler,
        public readonly mixed $payload,
        public readonly ?string $name = null,
        public readonly ?string $queue = null,
        public readonly int $priority = 5,
        public readonly ?int $maxRetries = 0,
        public readonly ?int $timeout = null,
        public readonly ?DateTimeImmutable $scheduledAt = null,
        public readonly bool $singleInstance = false,
        public readonly array $environments = [],
        public readonly array $dependsOn = [],
        public readonly string $cronExpression = '* * * * *',
        public readonly array $meta = [],
    ) {
    }

    public function withName(string $name): self
    {
        return $this->copy(['name' => $name]);
    }

    public function withQueue(?string $queue): self
    {
        return $this->copy(['queue' => $queue]);
    }

    public function withPriority(int $priority): self
    {
        return $this->copy(['priority' => $priority]);
    }

    public function withMaxRetries(?int $maxRetries): self
    {
        return $this->copy(['maxRetries' => $maxRetries]);
    }

    public function withTimeout(?int $timeout): self
    {
        return $this->copy(['timeout' => $timeout]);
    }

    public function withScheduledAt(?DateTimeImmutable $when): self
    {
        return $this->copy(['scheduledAt' => $when]);
    }

    public function withSingleInstance(bool $singleInstance = true): self
    {
        return $this->copy(['singleInstance' => $singleInstance]);
    }

    /**
     * @param list<string> $environments
     */
    public function withEnvironments(array $environments): self
    {
        return $this->copy(['environments' => $environments]);
    }

    /**
     * @param list<string> $dependsOn
     */
    public function withDependsOn(array $dependsOn): self
    {
        return $this->copy(['dependsOn' => $dependsOn]);
    }

    public function withCronExpression(string $expression): self
    {
        return $this->copy(['cronExpression' => $expression]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return $this->copy(['meta' => $meta]);
    }

    /**
     * Bridge from the v1 mutable Job builder. Reads only public/declared state — does
     * NOT carry callbacks or middleware (those remain v1-only until full v2 migration).
     */
    public static function fromLegacyJob(Job $job): self
    {
        $schedule = null;

        try {
            $reflected = new ReflectionProperty($job, 'schedule');

            /** @var DateTimeInterface|null $raw */
            $raw = $reflected->isInitialized($job) ? $reflected->getValue($job) : null;
            if ($raw instanceof DateTimeInterface) {
                $schedule = DateTimeImmutable::createFromInterface($raw);
            }
        } catch (ReflectionException) {
            // No schedule on the legacy Job — fine, leave as null.
        }

        $priority = 5;
        if (method_exists($job, 'getPriority')) {
            $rawPriority = $job->getPriority();
            $priority    = is_int($rawPriority) ? $rawPriority : 5;
        }

        // EnvironmentTrait::getEnvironments() returns the array carried by Job; the trait
        // is mixed into Job in src/Job.php so the method is always present here.
        $environments = array_values($job->getEnvironments());

        return new self(
            handler: $job->getJob(),
            payload: $job->getPayload(),
            name: $job->getName(),
            queue: $job->getQueue(),
            priority: $priority,
            maxRetries: $job->getMaxRetries(),
            timeout: $job->getTimeout(),
            scheduledAt: $schedule,
            singleInstance: $job->isSingleInstance(),
            environments: $environments,
            dependsOn: array_values($job->getDependsOn() ?? []),
            cronExpression: $job->getExpression(),
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function copy(array $changes): self
    {
        return new self(
            handler: $changes['handler'] ?? $this->handler,
            payload: array_key_exists('payload', $changes) ? $changes['payload'] : $this->payload,
            name: array_key_exists('name', $changes) ? $changes['name'] : $this->name,
            queue: array_key_exists('queue', $changes) ? $changes['queue'] : $this->queue,
            priority: $changes['priority'] ?? $this->priority,
            maxRetries: array_key_exists('maxRetries', $changes) ? $changes['maxRetries'] : $this->maxRetries,
            timeout: array_key_exists('timeout', $changes) ? $changes['timeout'] : $this->timeout,
            scheduledAt: array_key_exists('scheduledAt', $changes) ? $changes['scheduledAt'] : $this->scheduledAt,
            singleInstance: $changes['singleInstance'] ?? $this->singleInstance,
            environments: $changes['environments'] ?? $this->environments,
            dependsOn: $changes['dependsOn'] ?? $this->dependsOn,
            cronExpression: $changes['cronExpression'] ?? $this->cronExpression,
            meta: $changes['meta'] ?? $this->meta,
        );
    }
}
