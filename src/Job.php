<?php

declare(strict_types=1);

namespace Daycry\Jobs;

use Daycry\Jobs\Traits\ActivityTrait;
use Daycry\Jobs\Traits\DependableTrait;
use Daycry\Jobs\Traits\EnvironmentTrait;
use Daycry\Jobs\Traits\FrequenciesTrait;
use Daycry\Jobs\Traits\NameableTrait;
use Daycry\Jobs\Traits\NotificableTrait;
use Daycry\Jobs\Traits\StatusTrait;
use Daycry\Logs\Traits\LogTrait;

class Job
{
    use EnvironmentTrait;
    use FrequenciesTrait;
    use NameableTrait;
    use DependableTrait;
    use ActivityTrait;
    use StatusTrait;
    use LogTrait;
    use NotificableTrait;

    protected string $job;
    protected mixed $payload;
    protected bool $singleInstance = false;
    public function __construct(...$params)
    {
        $this->job = $params['job'] ?? '';
        $this->payload = $params['payload'] ?? '';
    }

    public function getJob(): string
    {
        return $this->job;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }

    public function singleInstance(bool $singleInstance = true): self
    {
        $this->singleInstance = $singleInstance;

        return $this;
    }

    public function isSingleInstance(): bool
    {
        return $this->singleInstance;
    }
}