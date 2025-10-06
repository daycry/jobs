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
 * Defines a post-execution callback Job descriptor that will be dispatched
 * (inline or enqueued) after the parent Job finishes.
 *
 * Legacy setCallback(url, options) removed in favor of setCallbackJob builder.
 */
trait CallbackTrait
{
    /**
     * Structured descriptor holding builder closure and inheritance options.
     *
     * @var object{builder:callable,inherit:array,filter:string,allowChain:bool}|null
     */
    protected ?object $callbackDescriptor = null;

    /**
     * Register a callback job builder.
     * The builder receives the parent Job and must return a configured child Job (without push()).
     * Inheritance options:
     *  - inherit: list of fields from parent to inject into child payload meta (output,error,attempts,name,source)
     *  - filter: 'always'|'success'|'failure'
     *  - allowChain: whether a callback job can itself define another callback (default false)
     */
    public function setCallbackJob(callable $builder, array $options = []): self
    {
        $descriptor          = new stdClass();
        $descriptor->builder = $builder;
        $descriptor->inherit = $options['inherit'] ?? ['output', 'error'];
        // Accept both 'filter' or shorthand 'on'
        $rawFilter = $options['filter'] ?? $options['on'] ?? 'always';
        // Normalize synonyms: 'error' -> 'failure'
        $normalized = match ($rawFilter) {
            'error'   => 'failure',
            'failure' => 'failure',
            'success' => 'success',
            default   => 'always',
        };
        $descriptor->filter       = $normalized;
        $descriptor->allowChain   = (bool) ($options['allowChain'] ?? false);
        $this->callbackDescriptor = $descriptor;

        return $this;
    }

    public function hasCallbackJob(): bool
    {
        return $this->callbackDescriptor !== null;
    }

    public function getCallbackDescriptor(): ?object
    {
        return $this->callbackDescriptor;
    }
}
