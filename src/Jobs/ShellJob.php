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

namespace Daycry\Jobs\Jobs;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Traits\InteractsWithCurrentJob;

/**
 * Executes a shell command using exec().
 * Payload: string command to execute. Returns captured output array.
 * NOTE: Commands are validated against whitelist if configured.
 */
class ShellJob extends Job implements JobInterface
{
    use InteractsWithCurrentJob;

    public function handle(mixed $payload): mixed
    {
        if (! is_string($payload)) {
            throw JobException::validationError('ShellJob payload must be a string command.');
        }

        $this->validateCommand($payload);

        // Use escapeshellcmd on the full command, then escapeshellarg on each argument
        $parts   = preg_split('/\s+/', trim($payload));
        $command = escapeshellarg(array_shift($parts));

        foreach ($parts as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        exec($command, $output);

        return $output;
    }

    /**
     * Validate command against whitelist if configured.
     */
    private function validateCommand(string $command): void
    {
        $config  = config('Jobs');
        $allowed = $config->allowedShellCommands ?? [];

        // Empty whitelist = allow all (backward compatible)
        if (empty($allowed)) {
            return;
        }

        // Extract command name (first token) and get basename to prevent path-based bypass
        $parts   = preg_split('/\\s+/', trim($command), 2);
        $cmdName = basename($parts[0] ?? '');

        // Normalize whitelist to lowercase for case-insensitive comparison
        $allowedLower = array_map(strtolower(...), array_map(basename(...), $allowed));

        // Check if command is in whitelist (case-insensitive)
        if (! in_array(strtolower($cmdName), $allowedLower, true)) {
            throw JobException::forShellCommandNotAllowed($cmdName);
        }
    }
}
