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

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;

/**
 * @internal
 */
abstract class TestCase extends CIUnitTestCase
{
    protected function setUp(): void
    {
        $this->resetServices();

        parent::setUp();
    }

    protected function injectMockQueueWorker(string $worker): void
    {
        $this->jobsConfig()->worker = $worker;
    }

    /**
     * Helper accessor returning typed Jobs config for static analysis.
     */
    protected function jobsConfig(): Jobs
    {
        return config(Jobs::class);
    }
}
