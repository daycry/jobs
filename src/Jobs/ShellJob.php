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
     *
     * Resolution rules:
     *  - If a whitelist entry contains a path separator (or resolves via realpath),
     *    we compare absolute paths (strongest mode — rejects /tmp/echo if only /usr/bin/echo is allowed).
     *  - If a whitelist entry is a bare name (e.g. 'ls'), we fall back to legacy
     *    case-insensitive basename matching with a deprecation warning. This mode
     *    is preserved to avoid breaking existing installs and will be removed in v2.0.
     */
    private function validateCommand(string $command): void
    {
        $config  = config('Jobs');
        $allowed = $config->allowedShellCommands ?? [];

        // Empty whitelist = allow all (backward compatible)
        if (empty($allowed)) {
            return;
        }

        $parts  = preg_split('/\\s+/', trim($command), 2);
        $rawCmd = $parts[0] ?? '';

        $candidate       = $this->resolveBinary($rawCmd);
        $allowedResolved = [];
        $hasLegacyEntry  = false;

        foreach ($allowed as $entry) {
            $resolved = $this->resolveBinary((string) $entry);
            if ($resolved !== null) {
                $allowedResolved[] = $resolved;
            } else {
                $hasLegacyEntry = true;
            }
        }

        // Strongest match: candidate path resolved AND in whitelist of resolved paths
        if ($candidate !== null && in_array($candidate, $allowedResolved, true)) {
            return;
        }

        // Legacy fallback: only if at least one whitelist entry was a bare name
        if ($hasLegacyEntry) {
            $cmdBase     = strtolower(basename($rawCmd));
            $allowedBase = array_map(strtolower(...), array_map(basename(...), $allowed));
            if (in_array($cmdBase, $allowedBase, true)) {
                log_message(
                    'warning',
                    "ShellJob: matched '{$rawCmd}' against legacy basename whitelist. "
                    . "Configure 'allowedShellCommands' with absolute paths to avoid this fallback (deprecated, removed in v2.0).",
                );

                return;
            }
        }

        throw JobException::forShellCommandNotAllowed($rawCmd);
    }

    /**
     * Resolve a command path to its absolute realpath, when possible.
     * Returns null when the input is a bare command name (no separator) and cannot be resolved,
     * signalling the caller that legacy basename matching should be used for that entry.
     */
    private function resolveBinary(string $cmd): ?string
    {
        if ($cmd === '') {
            return null;
        }

        // Treat as path only if it carries a separator. Otherwise it's a bare name
        // (we don't trust PATH lookup at this layer to keep the behaviour predictable).
        if (str_contains($cmd, '/') || str_contains($cmd, '\\')) {
            $resolved = realpath($cmd);

            return $resolved !== false ? $resolved : null;
        }

        return null;
    }
}
