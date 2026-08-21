<?php

declare(strict_types=1);

namespace App\Tests\Availability;

use App\Availability\CoverageEvaluator;
use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use App\Enum\AvailabilityCoverage;
use PHPUnit\Framework\TestCase;

/**
 * Task 30 (AC-6): the full matrix behind `CoverageEvaluator::evaluate()` --
 * candidate inside one range, exactly equal to one range, spanning two
 * normalized ranges with a gap, starting before/ending inside a range,
 * touching a range only at an endpoint (the deliberate `UNAVAILABLE`
 * asymmetry with `TimeRange::overlapsOrTouches()`), a day with no ranges at
 * all, and both the 0 and 1440 minute boundaries.
 */
final class CoverageEvaluatorTest extends TestCase
{
    private CoverageEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new CoverageEvaluator();
    }

    public function testCandidateFullyInsideOneRangeIsFullyAvailable(): void
    {
        $week = new WeeklyAvailability([1 => [new TimeRange(9 * 60, 17 * 60)]]);

        $result = $this->evaluator->evaluate($week, 1, new TimeRange(10 * 60, 12 * 60));

        self::assertSame(AvailabilityCoverage::FULLY_AVAILABLE, $result);
    }

    public function testCandidateExactlyEqualToOneRangeIsFullyAvailable(): void
    {
        $week = new WeeklyAvailability([1 => [new TimeRange(9 * 60, 17 * 60)]]);

        $result = $this->evaluator->evaluate($week, 1, new TimeRange(9 * 60, 17 * 60));

        self::assertSame(AvailabilityCoverage::FULLY_AVAILABLE, $result);
    }

    public function testCandidateSpanningTwoNormalizedRangesWithAGapIsPartiallyAvailable(): void
    {
        $week = new WeeklyAvailability([1 => [new TimeRange(9 * 60, 10 * 60), new TimeRange(12 * 60, 13 * 60)]]);

        // 9:30-12:30 overlaps both ranges but is not fully covered by either.
        $result = $this->evaluator->evaluate($week, 1, new TimeRange(9 * 60 + 30, 12 * 60 + 30));

        self::assertSame(AvailabilityCoverage::PARTIALLY_AVAILABLE, $result);
    }

    public function testCandidateStartingBeforeAndEndingInsideARangeIsPartiallyAvailable(): void
    {
        $week = new WeeklyAvailability([1 => [new TimeRange(9 * 60, 17 * 60)]]);

        $result = $this->evaluator->evaluate($week, 1, new TimeRange(8 * 60, 10 * 60));

        self::assertSame(AvailabilityCoverage::PARTIALLY_AVAILABLE, $result);
    }

    public function testCandidateTouchingARangeOnlyAtAnEndpointIsUnavailableNotPartial(): void
    {
        $week = new WeeklyAvailability([1 => [new TimeRange(9 * 60, 12 * 60)]]);

        // Saved 9am-12pm, candidate 12pm-1pm: they only touch at minute 720,
        // sharing no minute -- the deliberate asymmetry with
        // TimeRange::overlapsOrTouches(), which would treat this as merge-able.
        $result = $this->evaluator->evaluate($week, 1, new TimeRange(12 * 60, 13 * 60));

        self::assertSame(AvailabilityCoverage::UNAVAILABLE, $result);
    }

    public function testADayWithNoRangesAtAllIsUnavailable(): void
    {
        $week = new WeeklyAvailability([]);

        $result = $this->evaluator->evaluate($week, 1, new TimeRange(9 * 60, 10 * 60));

        self::assertSame(AvailabilityCoverage::UNAVAILABLE, $result);
    }

    public function testTheZeroMinuteBoundaryIsRespected(): void
    {
        $week = new WeeklyAvailability([1 => [new TimeRange(0, 60)]]);

        $result = $this->evaluator->evaluate($week, 1, new TimeRange(0, 30));

        self::assertSame(AvailabilityCoverage::FULLY_AVAILABLE, $result);
    }

    public function testThe1440MinuteBoundaryIsRespected(): void
    {
        $week = new WeeklyAvailability([1 => [new TimeRange(23 * 60, 1440)]]);

        $result = $this->evaluator->evaluate($week, 1, new TimeRange(23 * 60 + 30, 1440));

        self::assertSame(AvailabilityCoverage::FULLY_AVAILABLE, $result);
    }

    public function testANonOverlappingCandidateOnADifferentPartOfTheDayIsUnavailable(): void
    {
        $week = new WeeklyAvailability([1 => [new TimeRange(9 * 60, 10 * 60)]]);

        $result = $this->evaluator->evaluate($week, 1, new TimeRange(14 * 60, 15 * 60));

        self::assertSame(AvailabilityCoverage::UNAVAILABLE, $result);
    }
}
