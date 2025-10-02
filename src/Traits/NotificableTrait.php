<?php

declare(strict_types=1);

namespace Daycry\Jobs\Traits;

use Daycry\Jobs\Result;

trait NotificableTrait
{
    protected bool $notifyOnFailure = false;
    protected bool $notifyOnSuccess = false;

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

    public function notify(Result $result): bool
    {
        $email  = service('email');
        $parser = service('parser');

        $content = $result->getData();
        $normalizedOutput = null;
        if (is_array($content) || is_object($content)) {
            $normalizedOutput = json_encode($content);
        } elseif ($content !== null) {
            $normalizedOutput = $content;
        }

        $email->setMailType('html');
        $email->setFrom(config('Jobs')->from, config('Jobs')->fromName);
        $email->setTo(config('Jobs')->to);
        $email->setSubject($parser->setData(['job' => $this->getName()])->renderString('Job {job} just finished running.'));
        $email->setMessage($parser->setData([
            'name'     => $this->getName(),
            'runStart' => $this->getStartAt(),
            'duration' => $this->duration(),
            'output'   => ($result->isSuccess() ? $normalizedOutput : null),
            'error'    => ($result->isSuccess() !== true) ? $normalizedOutput : null,
        ])->render(config('Jobs')->emailNotificationView));

        return $email->send();
    }
}