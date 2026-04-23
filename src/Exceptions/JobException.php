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

namespace Daycry\Jobs\Exceptions;

use CodeIgniter\Exceptions\RuntimeException;

/**
 * Domain-specific exceptions for job definition & execution validation.
 * Provides named constructors for clearer intent at throw sites.
 */
class JobException extends RuntimeException
{
    public static function forInvalidJob(string $job): self
    {
        return new self("The job '{$job}' is not valid.");
    }

    public static function TaskAlreadyRunningException(string $job): self
    {
        return new self("The job '{$job}' is already running.");
    }

    public static function forInvalidLogType(): self
    {
        return new self('The log type is not valid.');
    }

    public static function validationError(mixed $errors): static
    {
        return new self($errors);
    }

    public static function forInvalidMethod(string $method): static
    {
        return new self(lang('HTTP.methodNotFound', [$method]));
    }

    public static function forInvalidPriority(int $priority): static
    {
        return new self("The priority '{$priority}' is not valid. It must be between 0 and 10.");
    }

    public static function forShellCommandNotAllowed(string $command): static
    {
        return new self("Shell command '{$command}' is not in the whitelist of allowed commands.");
    }

    public static function forJobTimeout(string $jobName, int $timeout): static
    {
        return new self("Job '{$jobName}' exceeded maximum execution time of {$timeout} seconds.");
    }

    public static function forRateLimitExceeded(string $queue, int $limit): static
    {
        return new self("Queue '{$queue}' has exceeded rate limit of {$limit} jobs per minute.");
    }
}
