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

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class QueueRunCommandMetricsTest extends TestCase
{
    public function testSkippedPlaceholder(): void
    {
        $this->markTestSkipped('Placeholder; real QueueRunCommand metrics integration test pending.');
    }
}
