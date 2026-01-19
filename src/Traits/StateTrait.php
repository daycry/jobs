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

namespace Daycry\Jobs\Traits;

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Job;

/**
 * Unified trait for job state management and notifications.
 * Combines StatusTrait and NotificableTrait functionality.
 *
 * @mixin Job
 */
trait StateTrait
{
    protected bool $enabled         = true;
    protected bool $notifyOnFailure = false;
    protected bool $notifyOnSuccess = false;

    // ============================================================
    // Status & Single Instance (formerly StatusTrait)
    // ============================================================

    public function saveRunningFlag(): bool
    {
        $cache = service('cache');

        return $cache->save('job_running', $this->getName(), 0);
    }

    public function isRunning(): bool
    {
        $cache = service('cache');

        return (bool) ($cache->get('job_running') === $this->getName());
    }

    public function clearRunningFlag(): bool
    {
        $cache = service('cache');

        return $cache->delete('job_running');
    }

    public function disable(): self
    {
        $this->enabled = false;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ============================================================
    // Notifications (formerly NotificableTrait)
    // ============================================================

    public function notifyOnFailure(bool $notify = true): self
    {
        $this->notifyOnFailure = $notify;

        return $this;
    }

    public function notifyOnSuccess(bool $notify = true): self
    {
        $this->notifyOnSuccess = $notify;

        return $this;
    }

    public function shouldNotifyOnFailure(): bool
    {
        return $this->notifyOnFailure;
    }

    public function shouldNotifyOnSuccess(): bool
    {
        return $this->notifyOnSuccess;
    }

    public function notifyOnCompletion(bool $notify = true): self
    {
        $this->notifyOnFailure = $notify;
        $this->notifyOnSuccess = $notify;

        return $this;
    }

    public function notify(ExecutionResult $result): bool
    {
        $email  = service('email');
        $parser = service('parser');

        $content          = $result->success ? $result->output : $result->error;
        $normalizedOutput = null;
        if (is_array($content) || is_object($content)) {
            $normalizedOutput = json_encode($content);
        } elseif ($content !== null) {
            $normalizedOutput = (string) $content;
        }

        $email->setMailType('html');
        $email->setFrom(config('Jobs')->from, config('Jobs')->fromName);
        $email->setTo(config('Jobs')->to);
        $email->setSubject($parser->setData(['job' => $this->getName()])->renderString('Job {job} just finished running.'));
        $email->setMessage($parser->setData([
            'name'     => $this->getName(),
            'runStart' => null,
            'duration' => null,
            'output'   => ($result->success ? $normalizedOutput : null),
            'error'    => ($result->success !== true) ? $normalizedOutput : null,
        ])->render(config('Jobs')->emailNotificationView));

        return $email->send();
    }
}
