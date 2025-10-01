<?php

declare(strict_types=1);

namespace Daycry\Jobs\Traits;

trait DependableTrait
{
    protected array $dependsOn = [];

    public function dependsOn(array|string $jobNames): self
    {
        $this->dependsOn = is_array($jobNames) ? $jobNames : [$jobNames];

        return $this;
    }

    public function getDependsOn(): ?array
    {
        return $this->dependsOn;
    }
}