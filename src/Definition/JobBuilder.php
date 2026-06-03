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

namespace Daycry\Jobs\Definition;

use Cron\CronExpression;
use DateTimeImmutable;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Queues\BackendFactory;
use RuntimeException;

/**
 * Mutable, fluent builder that accumulates job configuration and produces an immutable
 * {@see JobDefinition}.
 *
 * This is the v3 replacement for the v1 mutable {@see \Daycry\Jobs\Job} API. Where Job mixed
 * scheduling, identity and dispatch into a single long-lived mutable object, JobBuilder is a
 * throwaway accumulator: callers chain setters and then materialise the result with
 * {@see toDefinition()} (a value object) or {@see dispatch()} (enqueue onto a backend).
 *
 * Frequency helpers port the exact cron semantics of {@see \Daycry\Jobs\Traits\FrequenciesTrait}
 * by keeping the five standard cron fields (minute, hour, day-of-month, month, day-of-week) and
 * recomposing the expression on every mutation.
 */
final class JobBuilder
{
    private ?string $name = null;

    private ?string $queue = null;

    private int $priority = 5;

    private ?int $maxRetries = 0;

    private ?int $timeout = null;

    private ?DateTimeImmutable $scheduledAt = null;

    private bool $singleInstance = false;

    /** @var list<string> */
    private array $environments = [];

    /** @var list<string> */
    private array $dependsOn = [];

    private ?string $idempotencyKey = null;

    private bool $enabled = true;

    /**
     * The five standard cron fields: minute, hour, day-of-month, month, day-of-week.
     *
     * @var array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private array $cronParts = ['*', '*', '*', '*', '*'];

    private readonly Jobs $config;

    public function __construct(
        private readonly string $handler,
        private readonly mixed $payload = null,
        ?Jobs $config = null,
    ) {
        $this->config = $config ?? config('Jobs');
    }

    /**
     * The resolved library configuration backing this builder.
     *
     * The v3 facade {@see \Daycry\Jobs\Jobs} routes its config resolution through a builder
     * instance: calling config('Jobs') from the root {@see \Daycry\Jobs} namespace is
     * ambiguous (the facade class is also named Jobs), whereas resolving it via the readonly
     * {@see Jobs} property here yields the {@see \Daycry\Jobs\Config\Jobs} type cleanly.
     */
    public function config(): Jobs
    {
        return $this->config;
    }

    public function named(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function queue(?string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function maxRetries(?int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;

        return $this;
    }

    public function timeout(?int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function scheduledAt(?DateTimeImmutable $when): self
    {
        $this->scheduledAt = $when;

        return $this;
    }

    public function singleInstance(bool $singleInstance = true): self
    {
        $this->singleInstance = $singleInstance;

        return $this;
    }

    /**
     * Restrict execution to the given CI4 environments. Accepts either a variadic list or a
     * single array argument.
     *
     * @param list<string>|string $envs
     */
    public function environments(array|string ...$envs): self
    {
        $this->environments = $this->flatten($envs);

        return $this;
    }

    /**
     * Declare the job names that must succeed first. Accepts either a variadic list or a single
     * array argument.
     *
     * @param list<string>|string $names
     */
    public function dependsOn(array|string ...$names): self
    {
        $this->dependsOn = $this->flatten($names);

        return $this;
    }

    public function idempotencyKey(?string $idempotencyKey): self
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function enabled(bool $enabled = true): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function disable(): self
    {
        return $this->enabled(false);
    }

    /**
     * Set the schedule from a raw crontab expression string.
     *
     * @throws RuntimeException When the expression is not a valid cron string.
     */
    public function cron(string $expression): self
    {
        if (! CronExpression::isValidExpression($expression)) {
            throw new RuntimeException('Invalid cron expression: ' . $expression);
        }

        $this->setExpression((new CronExpression($expression))->getExpression());

        return $this;
    }

    // ============================================================
    // Frequency helpers (ported from FrequenciesTrait semantics)
    // ============================================================

    /**
     * Run every minute, or every $minutes minutes when provided.
     */
    public function everyMinute(?int $minutes = null): self
    {
        return $this->applyParts([0 => $minutes === null ? '*' : '*/' . $minutes]);
    }

    /**
     * Run every $minutes minutes (alias of {@see everyMinute()} with an explicit interval).
     */
    public function everyXMinutes(int $minutes): self
    {
        return $this->applyParts([0 => '*/' . $minutes]);
    }

    /**
     * Run at the top of every hour.
     */
    public function hourly(): self
    {
        return $this->applyParts([0 => '0', 1 => '*']);
    }

    /**
     * Run once an hour at the given minute past the hour.
     */
    public function hourlyAt(int $minute): self
    {
        return $this->applyParts([0 => (string) $minute, 1 => '*']);
    }

    /**
     * Run daily at midnight.
     */
    public function daily(): self
    {
        return $this->applyParts([0 => '0', 1 => '0']);
    }

    /**
     * Run daily at the given 'HH:MM' time.
     */
    public function dailyAt(string $time): self
    {
        [$minute, $hour] = $this->parseTime($time);

        return $this->applyParts([0 => $minute, 1 => $hour]);
    }

    /**
     * Run weekly on Sunday at midnight.
     */
    public function weekly(): self
    {
        return $this->applyParts([0 => '0', 1 => '0', 4 => '0']);
    }

    /**
     * Run on the first day of every month at midnight.
     */
    public function monthly(): self
    {
        return $this->applyParts([0 => '0', 1 => '0', 2 => '1']);
    }

    /**
     * Run on the first day of each quarter (Jan/Apr/Jul/Oct) at midnight.
     */
    public function quarterly(): self
    {
        return $this->applyParts([0 => '0', 1 => '0', 2 => '1', 3 => '*/3']);
    }

    /**
     * Run on the first day of the year at midnight.
     */
    public function yearly(): self
    {
        return $this->applyParts([0 => '0', 1 => '0', 2 => '1', 3 => '1']);
    }

    // ============================================================
    // Terminators
    // ============================================================

    /**
     * Materialise the accumulated configuration into an immutable {@see JobDefinition}.
     */
    public function toDefinition(): JobDefinition
    {
        return new JobDefinition(
            handler: $this->handler,
            payload: $this->payload,
            name: $this->name,
            queue: $this->queue,
            priority: $this->priority,
            maxRetries: $this->maxRetries,
            timeout: $this->timeout,
            scheduledAt: $this->scheduledAt,
            singleInstance: $this->singleInstance,
            environments: $this->environments,
            dependsOn: $this->dependsOn,
            cronExpression: $this->composeExpression(),
            meta: [],
            enabled: $this->enabled,
            idempotencyKey: $this->idempotencyKey,
        );
    }

    /**
     * Enqueue the definition onto the named backend (or the configured default) and return the
     * backend-assigned id.
     */
    public function dispatch(?string $backend = null): string
    {
        $resolved = BackendFactory::make($this->config, $backend);

        return $resolved->enqueue($this->toDefinition());
    }

    // ============================================================
    // Internal cron helpers
    // ============================================================

    /**
     * Apply field overrides keyed by cron field index (0-4) onto the running parts, then
     * recompose through {@see CronExpression} so the stored expression is always normalised.
     *
     * @param array<int, string> $overrides
     */
    private function applyParts(array $overrides): self
    {
        $cron = new CronExpression($this->composeExpression());

        foreach ($overrides as $index => $value) {
            $cron->setPart($index, $value);
        }

        $this->setExpression($cron->getExpression());

        return $this;
    }

    private function composeExpression(): string
    {
        return implode(' ', $this->cronParts);
    }

    private function setExpression(string $expression): void
    {
        $fields = explode(' ', $expression);

        if (count($fields) !== 5) {
            throw new RuntimeException('Invalid cron expression: ' . $expression);
        }

        $this->cronParts = [
            0 => $fields[0],
            1 => $fields[1],
            2 => $fields[2],
            3 => $fields[3],
            4 => $fields[4],
        ];
    }

    /**
     * Parse a 'HH:MM' time string into [minute, hour] cron field strings, normalised without
     * leading zeros (so '02:30' yields '2' for the hour, matching standard crontab output).
     *
     * @return array{0: string, 1: string} [minute, hour]
     */
    private function parseTime(string $time): array
    {
        $timestamp = strtotime($time);

        if ($timestamp === false) {
            throw new RuntimeException('Invalid time string: ' . $time);
        }

        return [
            (string) (int) date('i', $timestamp),
            (string) (int) date('H', $timestamp),
        ];
    }

    /**
     * Flatten a variadic of strings and/or string arrays into a single re-indexed list.
     *
     * @param array<int, list<string>|string> $values
     *
     * @return list<string>
     */
    private function flatten(array $values): array
    {
        $flat = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $flat[] = $item;
                }

                continue;
            }

            $flat[] = $value;
        }

        return $flat;
    }
}
