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

namespace Daycry\Jobs\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Job;

/**
 * Email notification service for job lifecycle events.
 *
 * Extracted from StateTrait::notify() in v1.2 to enable dependency injection
 * (and therefore testability without service-locator side effects).
 *
 * The default factory in Config\Services builds an instance backed by the
 * CodeIgniter `email` and `parser` services; tests can pass mocks directly.
 */
class NotificationService
{
    public function __construct(
        private readonly object $email,
        private readonly object $parser,
        private readonly string $from,
        private readonly string $fromName,
        private readonly string $to,
        private readonly string $view,
        private readonly string $timezone = 'UTC',
    ) {
    }

    /**
     * Render and send the notification email for a finished job execution.
     *
     * Returns true on success and false if the underlying mailer rejected the message.
     * Failures are logged at warning level so operators can correlate with delivery
     * problems without the calling code having to repeat that boilerplate.
     */
    public function send(Job $job, ExecutionResult $result): bool
    {
        $content          = $result->success ? $result->output : $result->error;
        $normalizedOutput = $this->normalizeContent($content);

        $tz       = new DateTimeZone($this->timezone);
        $runStart = (new DateTimeImmutable('@' . (int) $result->startedAt))->setTimezone($tz)->format('Y-m-d H:i:s');
        $duration = number_format($result->durationSeconds(), 3) . 's';

        $email  = $this->email;
        $parser = $this->parser;

        $email->setMailType('html');
        $email->setFrom($this->from, $this->fromName);
        $email->setTo($this->to);
        $email->setSubject($parser->setData(['job' => $job->getName()])->renderString('Job {job} just finished running.'));
        $email->setMessage($parser->setData([
            'name'     => esc($job->getName()),
            'runStart' => $runStart,
            'duration' => $duration,
            'output'   => esc($result->success ? ($normalizedOutput ?? '') : ''),
            'error'    => esc($result->success ? '' : $normalizedOutput ?? ''),
        ])->render($this->view));

        $sent = (bool) $email->send();
        if (! $sent) {
            log_message('warning', "NotificationService: email notification failed for job {$job->getName()}.");
        }

        return $sent;
    }

    private function normalizeContent(mixed $content): ?string
    {
        if ($content === null) {
            return null;
        }
        if (is_object($content)) {
            $encoded = json_encode($content);

            return $encoded === false ? null : $encoded;
        }

        return (string) $content;
    }
}
