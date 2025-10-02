<?php

namespace Daycry\Jobs\Exceptions;

use CodeIgniter\Exceptions\RuntimeException;

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
}
