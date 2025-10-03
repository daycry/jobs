<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QueueRunCommandMetricsTest extends TestCase
{
    public function testSkippedPlaceholder(): void
    {
        $this->markTestSkipped('Placeholder; real QueueRunCommand metrics integration test pending.');
    }
}
