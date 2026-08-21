<?php

declare(strict_types=1);

namespace App\Service;

use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use App\Entity\PlayerAvailabilitySlot;

/**
 * AC-22's trainer-facing "Best Times" summary: `"Mon 5-8pm, Wed 6-9pm"`, with
 * a `"+N more"` tail once more than `$maxDays` days have availability. A
 * plain service rather than a Twig filter, so the same string is reachable
 * from a future API/export without touching templates.
 *
 * A day is only ever included when it has at least one slot -- an empty
 * (Not Available) day contributes nothing, per D5's absence-as-Not-Available
 * encoding (AC-24). When every day is empty, {@see summarize()} returns the
 * literal string `"Not available"`.
 *
 * Task 24 (D2d, AC-5): the day-label/range-formatting/"+N more" logic lives
 * in {@see summarizeWeek()}, over the owner-agnostic `WeeklyAvailability`
 * value object -- reused by both S4's player path (via {@see summarize()},
 * now a two-line adapter) and this slice's coach path
 * (`Trainer\CoachController::index()`). `summarize()`'s signature and
 * behavior are unchanged; every existing S4 caller/test remains valid with
 * zero edits.
 */
final class AvailabilitySummaryFormatter
{
    private const DAY_ABBREVIATIONS = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    private const NOT_AVAILABLE = 'Not available';

    /**
     * @param list<PlayerAvailabilitySlot> $slots one player's slots, any
     *                                            order, any subset of days
     */
    public function summarize(array $slots, int $maxDays = 3): string
    {
        $rangesByDay = [];

        foreach ($slots as $slot) {
            $rangesByDay[$slot->getDayOfWeek()][] = new TimeRange($slot->getStartsAtMinute(), $slot->getEndsAtMinute());
        }

        return $this->summarizeWeek(new WeeklyAvailability($rangesByDay), $maxDays);
    }

    /**
     * Task 24 (D2d, AC-5): the owner-agnostic summary -- reused by S4's
     * player path (via {@see summarize()}) and this slice's coach path.
     */
    public function summarizeWeek(WeeklyAvailability $week, int $maxDays = 3): string
    {
        $dayStrings = [];

        foreach ($week->toArray() as $dayOfWeek => $ranges) {
            if ([] === $ranges) {
                continue;
            }

            $dayStrings[$dayOfWeek] = self::DAY_ABBREVIATIONS[$dayOfWeek].' '.$this->formatDay($ranges);
        }

        if ([] === $dayStrings) {
            return self::NOT_AVAILABLE;
        }

        ksort($dayStrings);

        $shown = \array_slice($dayStrings, 0, $maxDays);
        $remaining = \count($dayStrings) - \count($shown);

        $summary = implode(', ', $shown);

        if ($remaining > 0) {
            $summary .= \sprintf(' +%d more', $remaining);
        }

        return $summary;
    }

    /**
     * @param list<TimeRange> $dayRanges every range for one day
     */
    private function formatDay(array $dayRanges): string
    {
        $sorted = $dayRanges;
        usort($sorted, static fn (TimeRange $a, TimeRange $b): int => $a->startsAtMinute <=> $b->startsAtMinute);

        $ranges = array_map(
            fn (TimeRange $range): string => $this->formatRange($range->startsAtMinute, $range->endsAtMinute),
            $sorted,
        );

        return implode(', ', $ranges);
    }

    private function formatRange(int $startsAtMinute, int $endsAtMinute): string
    {
        $startPeriod = $this->periodOf($startsAtMinute);
        $endPeriod = $this->periodOf($endsAtMinute);

        // The start time only carries its own am/pm suffix when it differs
        // from the end's -- "5-8pm", not "5pm-8pm" -- matching AC-22's own
        // example. A range crossing noon/midnight ("11am-1pm") keeps both.
        $startLabel = $this->formatBoundary($startsAtMinute, $startPeriod !== $endPeriod);
        $endLabel = $this->formatBoundary($endsAtMinute, true);

        return $startLabel.'-'.$endLabel;
    }

    private function periodOf(int $minutes): string
    {
        return $this->hour24Of($minutes) >= 12 ? 'pm' : 'am';
    }

    private function hour24Of(int $minutes): int
    {
        return intdiv($minutes, 60) % 24;
    }

    private function formatBoundary(int $minutes, bool $includePeriod): string
    {
        $hour24 = $this->hour24Of($minutes);
        $minute = $minutes % 60;

        $hour12 = 0 === $hour24 % 12 ? 12 : $hour24 % 12;
        $time = 0 === $minute ? (string) $hour12 : \sprintf('%d:%02d', $hour12, $minute);

        return $includePeriod ? $time.$this->periodOf($minutes) : $time;
    }
}
