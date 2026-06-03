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

    public static function validationError(mixed $errors): self
    {
        return new self($errors);
    }

    public static function forInvalidMethod(string $method): self
    {
        return new self(lang('HTTP.methodNotFound', [$method]));
    }

    public static function forInvalidPriority(int $priority): self
    {
        return new self("The priority '{$priority}' is not valid. It must be between 0 and 10.");
    }

    public static function forShellCommandNotAllowed(string $command): self
    {
        return new self("Shell command '{$command}' is not in the whitelist of allowed commands.");
    }

    public static function forShellCommandsNotConfigured(): self
    {
        return new self(
            'ShellHandler is deny-by-default: configure Config\\Jobs::$allowedShellCommands '
            . 'with absolute paths, or set $allowAllShellCommands = true to opt out explicitly.',
        );
    }

    public static function forEventNotAllowed(string $event): self
    {
        return new self("Event '{$event}' is not in Config\\Jobs::\$allowedEvents.");
    }

    public static function forJobTimeout(string $jobName, int $timeout): self
    {
        return new self("Job '{$jobName}' exceeded maximum execution time of {$timeout} seconds.");
    }

    public static function forRateLimitExceeded(string $queue, int $limit): self
    {
        return new self("Queue '{$queue}' has exceeded rate limit of {$limit} jobs per minute.");
    }
}
