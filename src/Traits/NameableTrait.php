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
 * Supplies a human-readable and/or deterministic name for a job (hash fallback from payload).
 */
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
