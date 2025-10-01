<?php

declare(strict_types=1);

namespace Daycry\Jobs\Traits;

use CodeIgniter\I18n\Time;
use Cron\CronExpression;

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
}