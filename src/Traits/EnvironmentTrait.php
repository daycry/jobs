<?php

declare(strict_types=1);

namespace Daycry\Jobs\Traits;

trait EnvironmentTrait
{
    protected array $environments = [];

    protected function environments(...$environments): self
    {
        $this->environments = $environments;
        return $this;
    }

    protected function getEnvironments(): array
    {
        return $this->environments;
    }

    protected function inEnvironment(string $environment): bool
    {
        return in_array($environment, $this->environments, true);
    }
}
