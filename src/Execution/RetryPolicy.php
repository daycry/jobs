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

namespace Daycry\Jobs\Execution;

interface RetryPolicy
{
    /**
     * Devuelve el delay en segundos antes del siguiente intento.
     * $attempt es 1-based (primer intento = 1). Si retorna 0 no se duerme.
     */
    public function computeDelay(int $attempt): int;
}
