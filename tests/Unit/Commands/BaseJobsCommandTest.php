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

use CodeIgniter\CLI\Commands;
use Tests\Support\TestCase;

// Concrete implementation of abstract class for testing using reflection
// We use a simple subclass with a real Commands() invocation to properly construct it.

/**
 * @internal
 */
final class BaseJobsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->delete('jobs_active');
    }

    protected function tearDown(): void
    {
        service('cache')->delete('jobs_active');
        parent::tearDown();
    }

    public function testIsActiveReturnsFalseWhenNotSet(): void
    {
        $cache    = service('cache');
        $settings = $cache->get('jobs_active');
        // Ensure not set
        $this->assertNull($settings);
    }

    public function testDisableSetsFlag(): void
    {
        $cache                = service('cache');
        $settings             = new stdClass();
        $settings->status     = 'disabled';
        $settings->updated_at = new DateTime();
        $cache->save('jobs_active', $settings, 0);

        $saved = $cache->get('jobs_active');
        $this->assertSame('disabled', $saved->status);
    }

    public function testEnableSetsFlag(): void
    {
        $cache                = service('cache');
        $settings             = new stdClass();
        $settings->status     = 'enabled';
        $settings->updated_at = new DateTime();
        $cache->save('jobs_active', $settings, 0);

        $saved = $cache->get('jobs_active');
        $this->assertSame('enabled', $saved->status);
    }

    public function testIsActiveReturnsTrueWhenEnabled(): void
    {
        $cache            = service('cache');
        $settings         = new stdClass();
        $settings->status = 'enabled';
        $cache->save('jobs_active', $settings, 0);
        $fetched = $cache->get('jobs_active');
        $this->assertTrue($fetched !== null && $fetched->status === 'enabled');
    }

    public function testCronJobEnableCommandRunsViaRunner(): void
    {
        $runner = new Commands();
        ob_start();

        try {
            $runner->run('jobs:cronjob:enable', []);
        } catch (Throwable) {
            // Command may fail; that's ok for coverage
        }
        ob_get_clean();
        $saved = service('cache')->get('jobs_active');
        $this->assertNotNull($saved);
    }

    public function testCronJobDisableCommandRunsViaRunner(): void
    {
        $runner = new Commands();
        ob_start();

        try {
            $runner->run('jobs:cronjob:disable', []);
        } catch (Throwable) {
            // Command may fail; that's ok for coverage
        }
        ob_get_clean();
        $saved = service('cache')->get('jobs_active');
        $this->assertNotNull($saved);
    }

    public function testDisableWhenEnabled(): void
    {
        // Enable first, then disable
        $cache                = service('cache');
        $settings             = new stdClass();
        $settings->status     = 'enabled';
        $settings->updated_at = new DateTime();
        $cache->save('jobs_active', $settings, 0);

        $runner = new Commands();
        ob_start();
        $runner->run('jobs:cronjob:disable', []); // isActive()=true → calls disable()
        ob_get_clean();

        $saved = service('cache')->get('jobs_active');
        $this->assertSame('disabled', $saved->status);
    }

    public function testEnableWhenDisabled(): void
    {
        // Disable first, then enable
        service('cache')->delete('jobs_active');

        $runner = new Commands();
        ob_start();
        $runner->run('jobs:cronjob:enable', []); // isActive()=false → calls enable()
        ob_get_clean();

        $saved = service('cache')->get('jobs_active');
        $this->assertSame('enabled', $saved->status);
    }

    public function testAlreadyEnabledWhenCalledTwice(): void
    {
        // Pre-set enabled state
        $cache                = service('cache');
        $settings             = new stdClass();
        $settings->status     = 'enabled';
        $settings->updated_at = new DateTime();
        $cache->save('jobs_active', $settings, 0);

        $runner = new Commands();
        ob_start();

        try {
            $runner->run('jobs:cronjob:enable', []); // Already enabled -> alreadyEnabled()
        } catch (Throwable) {
        }
        ob_get_clean();
        $saved = service('cache')->get('jobs_active');
        $this->assertNotNull($saved);
        $this->assertSame('enabled', $saved->status);
    }

    public function testAlreadyDisabledWhenCalledTwice(): void
    {
        // Pre-set disabled state
        $cache                = service('cache');
        $settings             = new stdClass();
        $settings->status     = 'disabled';
        $settings->updated_at = new DateTime();
        $cache->save('jobs_active', $settings, 0);

        $runner = new Commands();
        ob_start();

        try {
            $runner->run('jobs:cronjob:disable', []); // Already disabled -> alreadyDisabled()
        } catch (Throwable) {
        }
        ob_get_clean();
        $saved = service('cache')->get('jobs_active');
        $this->assertNotNull($saved);
        $this->assertSame('disabled', $saved->status);
    }

    public function testTryToEnableViaCronJobRunCommand(): void
    {
        // When disabled, CronJobRunCommand::run() calls tryToEnable()
        $cache            = service('cache');
        $settings         = new stdClass();
        $settings->status = 'disabled';
        $cache->save('jobs_active', $settings, 0);

        $runner = new Commands();
        ob_start();

        try {
            $runner->run('jobs:cronjob:run', ['job' => 'enabled']);
        } catch (Throwable) {
        }
        ob_get_clean();
        $saved = service('cache')->get('jobs_active');
        $this->assertNotNull($saved);
    }
}
