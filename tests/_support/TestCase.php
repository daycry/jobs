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

/**
 * @internal
 */
abstract class TestCase extends CIUnitTestCase
{
    protected function setUp(): void
    {
        $this->resetServices();

        parent::setUp();

        // Asegurar configuración Jobs mínima para tests unitarios
        if (function_exists('config')) {
            $jobs = config('Jobs');
            if ($jobs) {
                $jobs->logPerformance = false;
                $jobs->log = 'file';
                $jobs->filePath = WRITEPATH . 'logs';
                if (!is_dir($jobs->filePath)) { @mkdir($jobs->filePath, 0777, true); }
            }
        }
    }

    protected function injectMockQueueWorker(string $worker): void
    {
        config('Jobs')->worker = $worker;
    }
}
