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
 * Unified trait for job identification and dependencies.
 * Combines NameableTrait and DependableTrait functionality.
 */
trait IdentityTrait
{
    protected ?string $name    = null;
    protected array $dependsOn = [];

    // ============================================================
    // Naming (formerly NameableTrait)
    // ============================================================

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

    // ============================================================
    // Dependencies (formerly DependableTrait)
    // ============================================================

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
