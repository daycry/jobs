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

    private function runningCacheKey(): string
    {
        return 'job_running_' . $this->getName();
    }

    public function saveRunningFlag(): bool
    {
        $cache = service('cache');
        // Use finite TTL to prevent deadlock if the job crashes without clearing the flag.
        // Default: job timeout + 60s buffer, or 600s if no timeout configured.
        $timeout = method_exists($this, 'getTimeout') ? ($this->getTimeout() ?? 0) : 0;
        $ttl     = $timeout > 0 ? $timeout + 60 : 600;

        return $cache->save($this->runningCacheKey(), true, $ttl);
    }

    public function isRunning(): bool
    {
        $cache = service('cache');

        return (bool) $cache->get($this->runningCacheKey());
    }

    public function clearRunningFlag(): bool
    {
        $cache = service('cache');

        return $cache->delete($this->runningCacheKey());
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
        if (is_object($content)) {
            $normalizedOutput = json_encode($content);
        } elseif ($content !== null) {
            $normalizedOutput = $content;
        }

        $email->setMailType('html');
        $email->setFrom(config('Jobs')->from, config('Jobs')->fromName);
        $email->setTo(config('Jobs')->to);
        $email->setSubject($parser->setData(['job' => $this->getName()])->renderString('Job {job} just finished running.'));
        $email->setMessage($parser->setData([
            'name'     => esc($this->getName()),
            'runStart' => '',
            'duration' => '',
            'output'   => esc($result->success ? ($normalizedOutput ?? '') : ''),
            'error'    => esc($result->success ? ('') : $normalizedOutput ?? ''),
        ])->render(config('Jobs')->emailNotificationView));

        return $email->send();
    }
}
