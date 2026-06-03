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
 * Executes a CodeIgniter spark command.
 *
 * The command string is trusted: untrusted queues MUST be gated via
 * Config\Jobs::$queueHandlers so this handler is unreachable from untrusted sources.
 */
final class CommandHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        if (! is_string($payload) || $payload === '') {
            throw JobException::validationError('CommandHandler payload must be a non-empty string.');
        }

        return command($payload);
    }
}
