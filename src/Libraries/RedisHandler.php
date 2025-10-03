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

namespace Daycry\Jobs\Libraries;

use CodeIgniter\Cache\Handlers\RedisHandler as CoreRedisHandler;
use Config\Cache;
use Redis;

/**
 * Thin wrapper around CodeIgniter4's Redis cache handler exposing raw Redis instance.
 */
class RedisHandler extends CoreRedisHandler
{
    public function __construct(Cache $config)
    {
        parent::__construct($config);
    }

    /**
     * Return underlying Redis instance (may be null if initialize() not called or connection failed).
     */
    public function getRedis(): ?Redis
    {
        return $this->redis ?? null;
    }
}
