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

use Daycry\Jobs\Config\Services;
use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Job;
use Daycry\Jobs\Notifications\NotificationService;

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

    public function saveRunningFlag(?int $ttl = null): bool
    {
        $cache = service('cache');

        if ($ttl === null) {
            // Use finite TTL to prevent deadlock if the job crashes without clearing the flag.
            // Default: job timeout + 60s buffer, or 600s if no timeout configured.
            // Long-running jobs that exceed the default 600s should be refreshed periodically
            // via JobLifecycleCoordinator's heartbeat (see saveRunningFlag(int $ttl) callers).
            $timeout = method_exists($this, 'getTimeout') ? ($this->getTimeout() ?? 0) : 0;
            $ttl     = $timeout > 0 ? $timeout + 60 : 600;
        }

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

    public function notify(ExecutionResult $result, ?NotificationService $service = null): bool
    {
        // v1.2 introduces NotificationService for DI/testability. Falls back to the
        // shared service-locator factory so existing callers keep working unchanged.
        $service ??= Services::jobsNotificationService();

        /** @var NotificationService $service */
        return $service->send($this, $result);
    }
}
