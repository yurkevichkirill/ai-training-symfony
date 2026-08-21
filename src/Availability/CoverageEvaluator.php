<?php

declare(strict_types=1);

namespace App\Availability;

use App\Enum\AvailabilityCoverage;

/**
 * The reusable conflict check AC-6 asks for: does a candidate time range on
 * one weekday fall entirely within, partially within, or entirely outside a
 * {@see WeeklyAvailability} grid. Final, stateless, Doctrine-free -- placed
 * beside `WeeklyAvailability`/`TimeRange` as a new file, not a method added
 * to either, because those are TASK-004 files under active parallel
 * modification (D2c).
 *
 * `FULLY_AVAILABLE` when one normalized range covers the candidate end to
 * end; `UNAVAILABLE` when no range shares a minute with it -- a range that
 * only *touches* the candidate at an endpoint is `UNAVAILABLE`, not partial,
 * the deliberate asymmetry with {@see TimeRange::overlapsOrTouches()} (which
 * exists for merging, not for coverage); `PARTIALLY_AVAILABLE` otherwise.
 * Normalizes defensively before comparing, so it is correct for a hand-built
 * week as well as a stored one.
 */
final class CoverageEvaluator
{
    public function evaluate(WeeklyAvailability $week, int $dayOfWeek, TimeRange $candidate): AvailabilityCoverage
    {
        $ranges = $week->normalized()->rangesForDay($dayOfWeek);

        if ([] === $ranges) {
            return AvailabilityCoverage::UNAVAILABLE;
        }

        foreach ($ranges as $range) {
            if ($range->startsAtMinute <= $candidate->startsAtMinute && $candidate->endsAtMinute <= $range->endsAtMinute) {
                return AvailabilityCoverage::FULLY_AVAILABLE;
            }
        }

        foreach ($ranges as $range) {
            if ($this->sharesAMinute($range, $candidate)) {
                return AvailabilityCoverage::PARTIALLY_AVAILABLE;
            }
        }

        return AvailabilityCoverage::UNAVAILABLE;
    }

    /**
     * True when the two ranges share at least one minute -- unlike
     * {@see TimeRange::overlapsOrTouches()}, a range that only touches the
     * candidate at an endpoint (e.g. saved "4-6pm" against a candidate
     * "6-7pm") does *not* count here; that is `UNAVAILABLE` per AC-6's
     * deliberate asymmetry.
     */
    private function sharesAMinute(TimeRange $range, TimeRange $candidate): bool
    {
        return $range->startsAtMinute < $candidate->endsAtMinute
            && $candidate->startsAtMinute < $range->endsAtMinute;
    }
}
