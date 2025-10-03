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
 * Declares dependency job names that should complete before this job executes (enforced externally).
 */
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
