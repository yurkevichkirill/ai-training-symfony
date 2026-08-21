<?php

declare(strict_types=1);

namespace App\Availability;

/**
 * One contiguous span of minutes-from-local-midnight (D5c), the same
 * `[startsAtMinute, endsAtMinute)` shape `PlayerAvailabilitySlot` persists --
 * this class is that entity's plain-PHP counterpart, with no Doctrine
 * dependency, so `WeeklyAvailability::normalized()` can be unit-tested
 * without booting the kernel.
 *
 * `endsAtMinute` may equal 1440 ("midnight at the end of the day"); nothing
 * else in `[0, 1440]` is out of range. `startsAtMinute < endsAtMinute`
 * always -- a zero-length or inverted range cannot be constructed.
 */
final readonly class TimeRange
{
    public const MINUTES_PER_DAY = 1440;

    public function __construct(
        public int $startsAtMinute,
        public int $endsAtMinute,
    ) {
        if ($this->startsAtMinute < 0 || $this->startsAtMinute > self::MINUTES_PER_DAY) {
            throw new \InvalidArgumentException(\sprintf('startsAtMinute must be between 0 and %d, got %d.', self::MINUTES_PER_DAY, $this->startsAtMinute));
        }

        if ($this->endsAtMinute < 0 || $this->endsAtMinute > self::MINUTES_PER_DAY) {
            throw new \InvalidArgumentException(\sprintf('endsAtMinute must be between 0 and %d, got %d.', self::MINUTES_PER_DAY, $this->endsAtMinute));
        }

        if ($this->startsAtMinute >= $this->endsAtMinute) {
            throw new \InvalidArgumentException(\sprintf('startsAtMinute (%d) must be strictly before endsAtMinute (%d).', $this->startsAtMinute, $this->endsAtMinute));
        }
    }

    /**
     * True when the two ranges share at least one minute, or one ends
     * exactly where the other begins -- the "touching" case
     * `WeeklyAvailability::normalized()` merges away, so that e.g. `5-6pm`
     * and `6-7pm` submitted separately store as the single row `5-7pm`.
     */
    public function overlapsOrTouches(self $other): bool
    {
        return $this->startsAtMinute <= $other->endsAtMinute
            && $other->startsAtMinute <= $this->endsAtMinute;
    }

    /**
     * The smallest range covering both `$this` and `$other`. Only meaningful
     * when {@see overlapsOrTouches()} is true for the pair; the caller (only
     * `WeeklyAvailability::normalized()`) is what guarantees that.
     */
    public function mergedWith(self $other): self
    {
        return new self(
            min($this->startsAtMinute, $other->startsAtMinute),
            max($this->endsAtMinute, $other->endsAtMinute),
        );
    }

    public function equals(self $other): bool
    {
        return $this->startsAtMinute === $other->startsAtMinute
            && $this->endsAtMinute === $other->endsAtMinute;
    }
}
