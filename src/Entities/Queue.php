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

namespace Daycry\Jobs\Entities;

use CodeIgniter\Entity\Entity;

/**
 * Queue entity representing a queued job record with schedule & status metadata.
 */
class Queue extends Entity
{
    /**
     * @var         list<string>
     * @phpstan-var list<string>
     * @psalm-var list<string>
     */
    protected $dates = [
        'schedule',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id'          => '?integer',
        'max_retries' => '?integer',
        'attempts'    => 'integer',
    ];
}
