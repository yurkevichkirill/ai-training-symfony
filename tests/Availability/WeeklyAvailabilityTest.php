<?php

declare(strict_types=1);

namespace App\Tests\Availability;

use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for `WeeklyAvailability::normalized()` (Task 18, D5c, AC-24):
 * sorting, merging touching/overlapping ranges, the 0/1440 boundary, and an
 * empty day.
 */
final class WeeklyAvailabilityTest extends TestCase
{
    public function testNormalizedSortsRangesSubmittedOutOfOrder(): void
    {
        $week = new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [
                new TimeRange(1080, 1200), // 6-8pm
                new TimeRange(1020, 1050), // 5-5:30pm
            ],
        ]);

        $ranges = $week->normalized()->rangesForDay(WeeklyAvailability::MONDAY);

        self::assertCount(2, $ranges);
        self::assertSame(1020, $ranges[0]->startsAtMinute);
        self::assertSame(1050, $ranges[0]->endsAtMinute);
        self::assertSame(1080, $ranges[1]->startsAtMinute);
        self::assertSame(1200, $ranges[1]->endsAtMinute);
    }

    public function testNormalizedMergesTouchingRanges(): void
    {
        // 5-6pm and 6-7pm submitted separately must collapse into one 5-7pm row.
        $week = new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [
                new TimeRange(1020, 1080),
                new TimeRange(1080, 1140),
            ],
        ]);

        $ranges = $week->normalized()->rangesForDay(WeeklyAvailability::MONDAY);

        self::assertCount(1, $ranges);
        self::assertSame(1020, $ranges[0]->startsAtMinute);
        self::assertSame(1140, $ranges[0]->endsAtMinute);
    }

    public function testNormalizedMergesOverlappingRanges(): void
    {
        // 5-8pm and 7-9pm overlap between 7-8pm and must collapse to 5-9pm.
        $week = new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [
                new TimeRange(1020, 1200),
                new TimeRange(1140, 1260),
            ],
        ]);

        $ranges = $week->normalized()->rangesForDay(WeeklyAvailability::MONDAY);

        self::assertCount(1, $ranges);
        self::assertSame(1020, $ranges[0]->startsAtMinute);
        self::assertSame(1260, $ranges[0]->endsAtMinute);
    }

    public function testNormalizedKeepsNonOverlappingRangesSeparate(): void
    {
        $week = new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [
                new TimeRange(1020, 1080), // 5-6pm
                new TimeRange(1140, 1200), // 7-8pm -- a genuine gap
            ],
        ]);

        $ranges = $week->normalized()->rangesForDay(WeeklyAvailability::MONDAY);

        self::assertCount(2, $ranges);
    }

    public function testNormalizedHandlesTheZeroAnd1440Boundary(): void
    {
        $week = new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [
                new TimeRange(0, 60),
                new TimeRange(1380, 1440),
            ],
        ]);

        $ranges = $week->normalized()->rangesForDay(WeeklyAvailability::MONDAY);

        self::assertCount(2, $ranges);
        self::assertSame(0, $ranges[0]->startsAtMinute);
        self::assertSame(60, $ranges[0]->endsAtMinute);
        self::assertSame(1380, $ranges[1]->startsAtMinute);
        self::assertSame(1440, $ranges[1]->endsAtMinute);
    }

    public function testNormalizedTreatsAnEmptyDayAsEmpty(): void
    {
        $week = new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [new TimeRange(1020, 1080)],
        ]);

        self::assertSame([], $week->normalized()->rangesForDay(WeeklyAvailability::TUESDAY));
    }

    public function testConstructorAlwaysExposesAllSevenIsoDays(): void
    {
        $week = new WeeklyAvailability();

        $days = array_keys($week->toArray());

        self::assertSame([1, 2, 3, 4, 5, 6, 7], $days);

        foreach ($days as $day) {
            self::assertSame([], $week->rangesForDay($day));
        }
    }

    public function testTimeRangeRejectsAnInvertedOrZeroLengthRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TimeRange(600, 600);
    }

    public function testTimeRangeRejectsOutOfBoundsMinutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TimeRange(-1, 60);
    }

    public function testTimeRangeAcceptsEndsAtMinuteEquals1440(): void
    {
        $range = new TimeRange(1380, TimeRange::MINUTES_PER_DAY);

        self::assertSame(1440, $range->endsAtMinute);
    }
}
