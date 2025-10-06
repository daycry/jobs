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

/**
 * Restricts job execution to specific application environments (e.g. production, staging).
 */
trait EnvironmentTrait
{
    protected array $environments = [];

    public function environments(...$environments): self
    {
        $this->environments = $environments;

        return $this;
    }

    public function getEnvironments(): array
    {
        return $this->environments;
    }

    public function inEnvironment(string $environment): bool
    {
        return in_array($environment, $this->environments, true);
    }
}
