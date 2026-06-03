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

namespace Tests\Unit\V3\Definition;

use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use Daycry\Jobs\Definition\JobBuilder;

/**
 * @internal
 */
final class JobBuilderTest extends CIUnitTestCase
{
    public function testEveryMinuteProducesStarExpression(): void
    {
        $definition = (new JobBuilder('command', 'jobs:test'))->everyMinute()->toDefinition();
        $this->assertSame('* * * * *', $definition->cronExpression);
    }

    public function testEveryXMinutesProducesStepExpression(): void
    {
        $this->assertSame('*/5 * * * *', (new JobBuilder('command'))->everyXMinutes(5)->toDefinition()->cronExpression);
        $this->assertSame('*/15 * * * *', (new JobBuilder('command'))->everyMinute(15)->toDefinition()->cronExpression);
    }

    public function testHourlyAndHourlyAt(): void
    {
        $this->assertSame('0 * * * *', (new JobBuilder('command'))->hourly()->toDefinition()->cronExpression);
        $this->assertSame('15 * * * *', (new JobBuilder('command'))->hourlyAt(15)->toDefinition()->cronExpression);
    }

    public function testDailyAndDailyAt(): void
    {
        $this->assertSame('0 0 * * *', (new JobBuilder('command'))->daily()->toDefinition()->cronExpression);
        $this->assertSame('30 2 * * *', (new JobBuilder('command'))->dailyAt('02:30')->toDefinition()->cronExpression);
    }

    public function testWeeklyMonthlyQuarterlyYearly(): void
    {
        $this->assertSame('0 0 * * 0', (new JobBuilder('command'))->weekly()->toDefinition()->cronExpression);
        $this->assertSame('0 0 1 * *', (new JobBuilder('command'))->monthly()->toDefinition()->cronExpression);
        $this->assertSame('0 0 1 */3 *', (new JobBuilder('command'))->quarterly()->toDefinition()->cronExpression);
        $this->assertSame('0 0 1 1 *', (new JobBuilder('command'))->yearly()->toDefinition()->cronExpression);
    }

    public function testRawCronSetsExpression(): void
    {
        $definition = (new JobBuilder('command'))->cron('5 4 * * 2')->toDefinition();
        $this->assertSame('5 4 * * 2', $definition->cronExpression);
    }

    public function testInvalidCronThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new JobBuilder('command'))->cron('not a cron');
    }

    public function testToDefinitionReflectsScalarConfiguration(): void
    {
        $when = new DateTimeImmutable('2026-06-03 12:00:00');

        $definition = (new JobBuilder('command', 'jobs:test'))
            ->named('nightly')
            ->queue('reports')
            ->priority(9)
            ->maxRetries(3)
            ->timeout(120)
            ->scheduledAt($when)
            ->singleInstance()
            ->idempotencyKey('abc-123')
            ->enabled(true)
            ->toDefinition();

        $this->assertSame('command', $definition->handler);
        $this->assertSame('jobs:test', $definition->payload);
        $this->assertSame('nightly', $definition->name);
        $this->assertSame('reports', $definition->queue);
        $this->assertSame(9, $definition->priority);
        $this->assertSame(3, $definition->maxRetries);
        $this->assertSame(120, $definition->timeout);
        $this->assertSame($when, $definition->scheduledAt);
        $this->assertTrue($definition->singleInstance);
        $this->assertSame('abc-123', $definition->idempotencyKey);
        $this->assertTrue($definition->enabled);
    }

    public function testDisableTurnsOffEnabled(): void
    {
        $definition = (new JobBuilder('command'))->disable()->toDefinition();
        $this->assertFalse($definition->enabled);
    }

    public function testEnvironmentsAcceptsVariadicAndArray(): void
    {
        $this->assertSame(
            ['production', 'staging'],
            (new JobBuilder('command'))->environments('production', 'staging')->toDefinition()->environments,
        );
        $this->assertSame(
            ['production', 'staging'],
            (new JobBuilder('command'))->environments(['production', 'staging'])->toDefinition()->environments,
        );
    }

    public function testDependsOnAcceptsVariadicAndArray(): void
    {
        $this->assertSame(
            ['a', 'b'],
            (new JobBuilder('command'))->dependsOn('a', 'b')->toDefinition()->dependsOn,
        );
        $this->assertSame(
            ['a', 'b'],
            (new JobBuilder('command'))->dependsOn(['a', 'b'])->toDefinition()->dependsOn,
        );
    }

    public function testFluentMethodsReturnSameInstance(): void
    {
        $builder = new JobBuilder('command');
        $this->assertSame($builder, $builder->named('x')->queue('q')->priority(1)->daily());
    }

    public function testDispatchWithSyncBackendRunsInlineAndReturnsSyntheticId(): void
    {
        $ran = false;

        $id = (new JobBuilder('closure', static function () use (&$ran): string {
            $ran = true;

            return 'ok';
        }))->dispatch('sync');

        $this->assertTrue($ran);
        $this->assertStringStartsWith('sync-', $id);
    }
}
