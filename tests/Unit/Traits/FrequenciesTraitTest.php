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

namespace Tests\Unit\Traits;

use Daycry\Jobs\Job;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Full coverage of FrequenciesTrait via Job.
 *
 * @internal
 */
final class FrequenciesTraitTest extends TestCase
{
    private function job(): Job
    {
        return new Job(job: 'command', payload: 'jobs:test');
    }

    public function testGetExpressionDefault(): void
    {
        $this->assertSame('* * * * *', $this->job()->getExpression());
    }

    public function testCronSetsExpression(): void
    {
        $job = $this->job()->cron('5 4 * * *');
        $this->assertSame('5 4 * * *', $job->getExpression());
    }

    public function testCronThrowsOnInvalidExpression(): void
    {
        $this->expectException(RuntimeException::class);
        $this->job()->cron('invalid');
    }

    public function testDailySetsCorrectExpression(): void
    {
        $this->assertSame('0 0 * * *', $this->job()->daily()->getExpression());
    }

    public function testDailyWithTimeSetsCorrectExpression(): void
    {
        $expr = $this->job()->daily('4:30 pm')->getExpression();
        // parsed minute=30, hour=16
        $this->assertStringStartsWith('30 16', $expr);
    }

    public function testHourlySetsCorrectExpression(): void
    {
        $this->assertSame('0 * * * *', $this->job()->hourly()->getExpression());
    }

    public function testHourlyWithMinuteSetsCorrectExpression(): void
    {
        $this->assertSame('15 * * * *', $this->job()->hourly(15)->getExpression());
    }

    public function testEveryHourDefault(): void
    {
        $this->assertSame('0 * * * *', $this->job()->everyHour()->getExpression());
    }

    public function testEveryHourEvery3Hours(): void
    {
        $this->assertStringContainsString('*/3', $this->job()->everyHour(3)->getExpression());
    }

    public function testEveryHourWithMinute(): void
    {
        $expr = $this->job()->everyHour(2, 15)->getExpression();
        $this->assertStringContainsString('15', $expr);
        $this->assertStringContainsString('*/2', $expr);
    }

    public function testBetweenHoursSetsCorrectExpression(): void
    {
        $this->assertStringContainsString('6-12', $this->job()->betweenHours(6, 12)->getExpression());
    }

    public function testHoursSetsCorrectExpression(): void
    {
        $this->assertStringContainsString('0,10,16', $this->job()->hours([0, 10, 16])->getExpression());
    }

    public function testEveryMinuteSetsCorrectExpression(): void
    {
        $this->assertSame('* * * * *', $this->job()->everyMinute()->getExpression());
    }

    public function testEveryMinuteWithIntervalAndMinute(): void
    {
        $this->assertSame('*/20 * * * *', $this->job()->everyMinute(20)->getExpression());
    }

    public function testEveryFiveMinutesSetsCorrectExpression(): void
    {
        $this->assertSame('*/5 * * * *', $this->job()->everyFiveMinutes()->getExpression());
    }

    public function testEveryFifteenMinutesSetsCorrectExpression(): void
    {
        $this->assertSame('*/15 * * * *', $this->job()->everyFifteenMinutes()->getExpression());
    }

    public function testEveryThirtyMinutesSetsCorrectExpression(): void
    {
        $this->assertSame('*/30 * * * *', $this->job()->everyThirtyMinutes()->getExpression());
    }

    public function testBetweenMinutesSetsCorrectExpression(): void
    {
        $this->assertStringContainsString('0-30', $this->job()->betweenMinutes(0, 30)->getExpression());
    }

    public function testMinutesArraySetsCorrectExpression(): void
    {
        $this->assertStringContainsString('0,20,40', $this->job()->minutes([0, 20, 40])->getExpression());
    }

    public function testDaysArraySetsCorrectExpression(): void
    {
        $this->assertStringContainsString('0,3', $this->job()->days([0, 3])->getExpression());
    }

    public function testDaysOfMonthSetsCorrectExpression(): void
    {
        $this->assertStringContainsString('1,15', $this->job()->daysOfMonth([1, 15])->getExpression());
    }

    public function testMonthsArraySetsCorrectExpression(): void
    {
        $this->assertStringContainsString('1,7', $this->job()->months([1, 7])->getExpression());
    }

    public function testSundaysSetsCorrectExpression(): void
    {
        $expr  = $this->job()->sundays()->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('0', $parts[4]);
    }

    public function testMondaysSetsCorrectExpression(): void
    {
        $expr  = $this->job()->mondays()->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('1', $parts[4]);
    }

    public function testTuesdaysSetsCorrectExpression(): void
    {
        $expr  = $this->job()->tuesdays()->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('2', $parts[4]);
    }

    public function testWednesdaysSetsCorrectExpression(): void
    {
        $expr  = $this->job()->wednesdays()->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('3', $parts[4]);
    }

    public function testThursdaysSetsCorrectExpression(): void
    {
        $expr  = $this->job()->thursdays()->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('4', $parts[4]);
    }

    public function testFridaysSetsCorrectExpression(): void
    {
        $expr  = $this->job()->fridays()->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('5', $parts[4]);
    }

    public function testSaturdaysSetsCorrectExpression(): void
    {
        $expr  = $this->job()->saturdays()->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('6', $parts[4]);
    }

    public function testMonthlySetsCorrectExpression(): void
    {
        $this->assertSame('0 0 1 * *', $this->job()->monthly()->getExpression());
    }

    public function testQuarterlySetsCorrectExpression(): void
    {
        $this->assertSame('0 0 1 */3 *', $this->job()->quarterly()->getExpression());
    }

    public function testYearlySetsCorrectExpression(): void
    {
        $this->assertSame('0 0 1 1 *', $this->job()->yearly()->getExpression());
    }

    public function testWeekdaysSetsCorrectExpression(): void
    {
        $this->assertSame('0 0 * * 1-5', $this->job()->weekdays()->getExpression());
    }

    public function testWeekendsSetsCorrectExpression(): void
    {
        $this->assertSame('0 0 * * 6-7', $this->job()->weekends()->getExpression());
    }

    public function testFluentChaining(): void
    {
        // everyMinute() only sets the minute field, leaving hour set by daily()
        $job = $this->job()->daily()->everyMinute();
        // After everyMinute() the minute part becomes '*'; hour remains 0 from daily()
        $this->assertStringStartsWith('* ', $job->getExpression());
    }

    public function testDailyWithTimeOverridesMinuteAndHour(): void
    {
        // Daily at 11pm → 0 23 * * *
        $expr  = $this->job()->daily('11:00 pm')->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('00', $parts[0]); // minute (zero-padded)
        $this->assertSame('23', $parts[1]); // hour
    }

    public function testMondaysWithTimeSetsCorrectHour(): void
    {
        $expr  = $this->job()->mondays('3:00 am')->getExpression();
        $parts = explode(' ', $expr);
        $this->assertSame('03', $parts[1]);
        $this->assertSame('1', $parts[4]);
    }

    public function testDaysWithIntegerSetsCorrectExpression(): void
    {
        // non-array path: $days gets wrapped in array
        $this->assertStringContainsString('3', $this->job()->days(3)->getExpression());
    }

    public function testDaysOfMonthWithIntegerSetsCorrectExpression(): void
    {
        // non-array path: $days gets wrapped in array
        $this->assertStringContainsString('15', $this->job()->daysOfMonth(15)->getExpression());
    }
}
