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

namespace Tests\Unit\V3\Execution;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Execution\JobContext;

/**
 * @internal
 */
final class JobContextTest extends CIUnitTestCase
{
    public function testHoldsPayloadAndDefaults(): void
    {
        $ctx = new JobContext(payload: ['a' => 1]);

        $this->assertSame(['a' => 1], $ctx->payload);
        $this->assertNull($ctx->name);
        $this->assertNull($ctx->queue);
        $this->assertSame(1, $ctx->attempt);
        $this->assertSame([], $ctx->meta);
    }

    public function testFromPayloadFactory(): void
    {
        $ctx = JobContext::fromPayload('hello');
        $this->assertSame('hello', $ctx->payload);
        $this->assertSame(1, $ctx->attempt);
    }

    public function testCarriesMetadata(): void
    {
        $ctx = new JobContext(payload: null, name: 'job-a', queue: 'reports', attempt: 3, meta: ['k' => 'v']);
        $this->assertSame('job-a', $ctx->name);
        $this->assertSame('reports', $ctx->queue);
        $this->assertSame(3, $ctx->attempt);
        $this->assertSame(['k' => 'v'], $ctx->meta);
    }
}
