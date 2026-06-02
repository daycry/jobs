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

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Executes an OS command via proc_open with an argv array (never through /bin/sh -c),
 * which removes the shell metacharacter attack surface entirely.
 *
 * Deny-by-default: with an empty allowlist (Config\Jobs::$allowedShellCommands) and
 * $allowAllShellCommands = false, execution is refused. Configure the allowlist with
 * absolute paths, or opt out explicitly via $allowAllShellCommands = true.
 *
 * Payload accepts either a string (split on whitespace) or, preferably, a list<string>
 * argv array so arguments with spaces survive intact.
 */
final class ShellHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $argv = $this->toArgv($ctx->payload);
        if ($argv === []) {
            throw JobException::validationError('ShellHandler payload must be a non-empty command.');
        }

        $this->authorize($argv[0]);

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($argv, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw JobException::validationError('ShellHandler could not start the process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stdout = $stdout === false ? '' : $stdout;
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Preserve the v1-style array-of-output-lines return shape.
        $lines = explode("\n", str_replace("\r\n", "\n", $stdout));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return list<string>
     */
    private function toArgv(mixed $payload): array
    {
        if (is_array($payload)) {
            return array_values(array_map(static fn ($v): string => (string) $v, $payload));
        }

        if (is_string($payload)) {
            $parts = preg_split('/\s+/', trim($payload));
            if ($parts === false) {
                $parts = [];
            }

            return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        }

        throw JobException::validationError('ShellHandler payload must be a string or an argv array.');
    }

    private function authorize(string $binary): void
    {
        $cfg     = config('Jobs');
        $allowed = $cfg->allowedShellCommands ?? [];

        if ($allowed === []) {
            if (($cfg->allowAllShellCommands ?? false) === true) {
                return;
            }

            throw JobException::forShellCommandsNotConfigured();
        }

        $candidate = realpath($binary);
        $candidate = $candidate === false ? $binary : $candidate;

        foreach ($allowed as $entry) {
            $resolved = realpath((string) $entry);
            $resolved = $resolved === false ? (string) $entry : $resolved;
            if ($candidate === $resolved) {
                return;
            }
        }

        throw JobException::forShellCommandNotAllowed($binary);
    }
}
