<?php

declare(strict_types=1);

namespace Daycry\Jobs\Traits;

trait NameableTrait
{
    protected ?string $name = null;

    public function named(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        $this->name ??= $this->job . '-' . md5(serialize($this->payload));

        return $this->name;
    }
}