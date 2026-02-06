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

namespace Daycry\Jobs\Generators;

use Daycry\Jobs\Interfaces\IdGeneratorInterface;

class UuidGenerator implements IdGeneratorInterface
{
    public function generate(int $bytes = 8, ?string $prefix = null): string
    {
        return service('uuid')->generate('v7')->toRfc4122();
    }
}
