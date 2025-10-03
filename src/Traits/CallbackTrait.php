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

use stdClass;

/**
 * Adds an optional HTTP callback descriptor (URL + options) to a Job for post-processing.
 */
trait CallbackTrait
{
    protected ?object $callback = null;

    public function setCallback(string $url, array $options = []): self
    {
        $this->callback          = new stdClass();
        $this->callback->url     = $url;
        $this->callback->options = $options;

        return $this;
    }

    public function getCallback()
    {
        return $this->callback;
    }
}
