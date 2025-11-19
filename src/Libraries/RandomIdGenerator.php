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

use Daycry\Jobs\Interfaces\IdGeneratorInterface;

class RandomIdGenerator implements IdGeneratorInterface
{
    public function generate(int $bytes = 8, ?string $prefix = null): string
    {
        $core = bin2hex(random_bytes($bytes));

        return $prefix ? ($prefix . '-' . $core) : $core;
    }
}
