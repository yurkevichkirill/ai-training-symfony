<?php

declare(strict_types=1);

namespace App\Availability;

/**
 * A player's full weekly availability grid -- seven days, ISO-8601
 * (`date('N')`: Monday = 1 ... Sunday = 7), each holding zero or more
 * {@see TimeRange}s. Plain PHP, no Doctrine dependency, so it is testable in
 * isolation from `PlayerAvailabilitySlot`/the database.
 *
 * A day with no ranges *is* "Not Available" (D5, AC-24) -- there is no third
 * state and no separate flag. `AvailabilityService` is what maps this to and
 * from `PlayerAvailabilitySlot` rows; this class knows nothing of Doctrine,
 * `User`, or persistence.
 */
final readonly class WeeklyAvailability
{
    public const MONDAY = 1;
    public const TUESDAY = 2;
    public const WEDNESDAY = 3;
    public const THURSDAY = 4;
    public const FRIDAY = 5;
    public const SATURDAY = 6;
    public const SUNDAY = 7;

    /** @var array<int, list<TimeRange>> always keyed 1..7, in construction order */
    private array $rangesByDay;

    /**
     * @param array<int, list<TimeRange>> $rangesByDay ISO day (1 Monday ..
     *                                                  7 Sunday) => that
     *                                                  day's ranges, in
     *                                                  whatever order they
     *                                                  were submitted. A day
     *                                                  key absent from this
     *                                                  array is treated
     *                                                  identically to one
     *                                                  present with an empty
     *                                                  list -- both are "Not
     *                                                  Available".
     */
    public function __construct(array $rangesByDay = [])
    {
        $normalized = [];
        for ($day = self::MONDAY; $day <= self::SUNDAY; ++$day) {
            $normalized[$day] = $rangesByDay[$day] ?? [];
        }

        $this->rangesByDay = $normalized;
    }

    /**
     * @return list<TimeRange> this day's ranges, empty when "Not Available"
     */
    public function rangesForDay(int $dayOfWeek): array
    {
        return $this->rangesByDay[$dayOfWeek] ?? [];
    }

    /**
     * @return array<int, list<TimeRange>> every ISO day 1..7, always present
     */
    public function toArray(): array
    {
        return $this->rangesByDay;
    }

    /**
     * Sorts each day's ranges by start time and merges any pair that
     * overlaps or touches (D5c) -- so two submissions describing the same
     * availability (in any order, with any redundant overlap) produce
     * byte-identical, minimal range sets. This is what makes AC-24's
     * evaluation and AC-22's summary string deterministic: there is exactly
     * one normal form for "available Monday 5-8pm", never two rows that
     * happen to describe the same span.
     */
    public function normalized(): self
    {
        $normalized = [];

        foreach ($this->rangesByDay as $day => $ranges) {
            $normalized[$day] = self::normalizeDay($ranges);
        }

        return new self($normalized);
    }

    /**
     * @param list<TimeRange> $ranges
     *
     * @return list<TimeRange> sorted by start time, with every
     *                          overlapping-or-touching pair merged into one
     */
    private static function normalizeDay(array $ranges): array
    {
        if ([] === $ranges) {
            return [];
        }

        $sorted = $ranges;
        usort($sorted, static fn (TimeRange $a, TimeRange $b): int => $a->startsAtMinute <=> $b->startsAtMinute);

        $merged = [$sorted[0]];

        foreach (\array_slice($sorted, 1) as $range) {
            $lastIndex = \array_key_last($merged);
            $last = $merged[$lastIndex];

            if ($last->overlapsOrTouches($range)) {
                $merged[$lastIndex] = $last->mergedWith($range);
            } else {
                $merged[] = $range;
            }
        }

        return array_values($merged);
    }
}
