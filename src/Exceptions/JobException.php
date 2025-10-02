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

    public static function validationError($errors)
    {
        return new self($errors);
    }

    public static function forInvalidMethod(string $method)
    {
        return new self(lang('HTTP.methodNotFound', [$method]));
    }

    public static function forInvalidPriority(int $priority)
    {
        return new self("The priority '{$priority}' is not valid. It must be between 0 and 10.");
    }
}
