<?php

declare(strict_types=1);

namespace Daycry\Jobs\Traits;

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

    public function notify(): bool
    {
        $email  = service('email');
        $parser = service('parser');

        $email->setMailType('html');
        $email->setFrom($this->config->from, $this->config->fromName);
        $email->setTo($this->config->to);
        $email->setSubject($parser->setData(['job' => $this->job->getName()])->renderString(lang('CronJob.emailSubject')));
        $email->setMessage($parser->setData([
            'name'     => $this->job->getName(),
            'runStart' => $this->job->getStartAt(),
            'duration' => $this->job->duration(),
            'output'   => $this->job->getOutput(),
            'error'    => $error,
        ])->render('Daycry\CronJob\Views\email_notification'));
        $email->send();
    }
}