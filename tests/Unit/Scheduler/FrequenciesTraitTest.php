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

namespace Tests\Unit\Scheduler;

use Daycry\Jobs\Job;
use RuntimeException;
use Tests\Support\TestCase;

/**
 * Comprehensive coverage of FrequenciesTrait helpers — every public method
 * should produce a deterministic cron expression. Added in v1.2 to close the
 * gap noted in the original audit (only ~2 tests previously covered ~30 helpers).
 *
 * @internal
 */
final class FrequenciesTraitTest extends TestCase
{
    private function job(): Job
    {
        return new Job(job: 'command', payload: 'jobs:test');
    }

    public function testDefaultExpressionIsEveryMinute(): void
    {
        $this->assertSame('* * * * *', $this->job()->getExpression());
    }

    public function testEveryMinuteWithNoArgKeepsWildcard(): void
    {
        $this->assertSame('* * * * *', $this->job()->everyMinute()->getExpression());
    }

    public function testEveryMinuteWithStep(): void
    {
        $this->assertSame('*/20 * * * *', $this->job()->everyMinute(20)->getExpression());
    }

    public function testEveryFiveMinutes(): void
    {
        $this->assertSame('*/5 * * * *', $this->job()->everyFiveMinutes()->getExpression());
    }

    public function testEveryFifteenMinutes(): void
    {
        $this->assertSame('*/15 * * * *', $this->job()->everyFifteenMinutes()->getExpression());
    }

    public function testEveryThirtyMinutes(): void
    {
        $this->assertSame('*/30 * * * *', $this->job()->everyThirtyMinutes()->getExpression());
    }

    public function testHourlyDefaultsToMinuteZero(): void
    {
        $this->assertSame('0 * * * *', $this->job()->hourly()->getExpression());
    }

    public function testHourlyWithMinute(): void
    {
        $this->assertSame('15 * * * *', $this->job()->hourly(15)->getExpression());
    }

    public function testEveryHourWithStepAndMinute(): void
    {
        $this->assertSame('15 */3 * * *', $this->job()->everyHour(3, 15)->getExpression());
    }

    public function testBetweenHours(): void
    {
        $this->assertSame('* 6-12 * * *', $this->job()->betweenHours(6, 12)->getExpression());
    }

    public function testHoursList(): void
    {
        $this->assertSame('* 0,10,16 * * *', $this->job()->hours([0, 10, 16])->getExpression());
    }

    public function testBetweenMinutes(): void
    {
        $this->assertSame('0-30 * * * *', $this->job()->betweenMinutes(0, 30)->getExpression());
    }

    public function testMinutesList(): void
    {
        $this->assertSame('0,20,40 * * * *', $this->job()->minutes([0, 20, 40])->getExpression());
    }

    public function testDailyAtMidnight(): void
    {
        $this->assertSame('0 0 * * *', $this->job()->daily()->getExpression());
    }

    public function testDailyWithTime(): void
    {
        $this->assertSame('21 16 * * *', $this->job()->daily('4:21 pm')->getExpression());
    }

    public function testWeekdays(): void
    {
        $this->assertSame('0 0 * * 1-5', $this->job()->weekdays()->getExpression());
    }

    public function testWeekends(): void
    {
        // Cron uses 0=Sunday, 6=Saturday. The library encodes the weekend as the range "6-7"
        // (Saturday through Sunday with day 7 being the alternative spelling for Sunday).
        $this->assertSame('0 0 * * 6-7', $this->job()->weekends()->getExpression());
    }

    public function testSundays(): void
    {
        $this->assertSame('0 0 * * 0', $this->job()->sundays()->getExpression());
    }

    public function testMondays(): void
    {
        $this->assertSame('0 0 * * 1', $this->job()->mondays()->getExpression());
    }

    public function testTuesdays(): void
    {
        $this->assertSame('0 0 * * 2', $this->job()->tuesdays()->getExpression());
    }

    public function testWednesdays(): void
    {
        $this->assertSame('0 0 * * 3', $this->job()->wednesdays()->getExpression());
    }

    public function testThursdays(): void
    {
        $this->assertSame('0 0 * * 4', $this->job()->thursdays()->getExpression());
    }

    public function testFridays(): void
    {
        $this->assertSame('0 0 * * 5', $this->job()->fridays()->getExpression());
    }

    public function testSaturdays(): void
    {
        $this->assertSame('0 0 * * 6', $this->job()->saturdays()->getExpression());
    }

    public function testDayOfWeekWithCustomTime(): void
    {
        // Hour part is zero-padded by the helper because the time is parsed via DateTime.
        $this->assertSame('15 03 * * 1', $this->job()->mondays('3:15 am')->getExpression());
    }

    public function testMonthly(): void
    {
        $this->assertSame('0 0 1 * *', $this->job()->monthly()->getExpression());
    }

    public function testMonthlyWithTime(): void
    {
        $this->assertSame('21 12 1 * *', $this->job()->monthly('12:21 pm')->getExpression());
    }

    public function testQuarterly(): void
    {
        $this->assertSame('0 0 1 */3 *', $this->job()->quarterly()->getExpression());
    }

    public function testYearly(): void
    {
        $this->assertSame('0 0 1 1 *', $this->job()->yearly()->getExpression());
    }

    public function testDaysList(): void
    {
        $this->assertSame('* * * * 0,3', $this->job()->days([0, 3])->getExpression());
    }

    public function testDaysOfMonth(): void
    {
        $this->assertSame('* * 1,15 * *', $this->job()->daysOfMonth([1, 15])->getExpression());
    }

    public function testMonths(): void
    {
        $this->assertSame('* * * 1,7 *', $this->job()->months([1, 7])->getExpression());
    }

    public function testCronAcceptsValidExpression(): void
    {
        $this->assertSame('*/5 * * * *', $this->job()->cron('*/5 * * * *')->getExpression());
    }

    public function testCronRejectsInvalidExpression(): void
    {
        $this->expectException(RuntimeException::class);
        $this->job()->cron('not a cron');
    }

    public function testFluentChainingComposesParts(): void
    {
        // Compose minutes + hours + day-of-month + months — the resulting cron
        // expression must contain every constraint we set, in order.
        $expr = $this->job()
            ->minutes([0, 30])
            ->betweenHours(8, 18)
            ->daysOfMonth([1, 15])
            ->months([1, 6])
            ->getExpression();

        $this->assertSame('0,30 8-18 1,15 1,6 *', $expr);
    }
}
