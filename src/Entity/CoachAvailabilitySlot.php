<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CoachAvailabilitySlotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One weekly-recurring availability range for a coach (AC-1). Column-for-
 * column the same shape S4's `PlayerAvailabilitySlot` proved out, but its
 * own table (D2) -- the owner column, FK and index set all differ, and
 * nothing in this slice queries availability across coaches, so this table
 * carries none of `player_availability_slot`'s roster-filter index.
 *
 * **No `is_unavailable` flag and no row-per-day placeholder.** The absence
 * of any row for a given weekday *is* "not available" (AC-1) -- the same
 * encoding S4 chose and `WeeklyAvailability` already implements.
 * **No time-zone column**: every value here is facility-local wall-clock
 * time, out of this slice's scope.
 *
 * `dayOfWeek` is ISO-8601, Monday = 1 ... Sunday = 7 (`date('N')`).
 * `startsAtMinute`/`endsAtMinute` are minutes from local midnight; 1440
 * ("midnight at the end of the day") is the only legal value for
 * `endsAtMinute` that equals the day's length. Hand-written CHECK
 * constraints enforce both ranges and `starts < ends` at the storage level.
 *
 * `CoachAvailabilityService::replaceWeek()` is the only writer: one
 * transaction deletes every row for the coach and inserts the normalized
 * replacement set (`CoachAvailabilitySlotRepository::replaceWeekFor()`).
 */
#[ORM\Entity(repositoryClass: CoachAvailabilitySlotRepository::class)]
#[ORM\Table(name: 'coach_availability_slot')]
#[ORM\Index(name: 'idx_coach_availability_slot_coach_day_start', columns: ['coach_id', 'day_of_week', 'starts_at_minute'])]
class CoachAvailabilitySlot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'coach_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $coach;

    #[ORM\Column(name: 'day_of_week', type: 'smallint')]
    private readonly int $dayOfWeek;

    #[ORM\Column(name: 'starts_at_minute', type: 'smallint')]
    private readonly int $startsAtMinute;

    #[ORM\Column(name: 'ends_at_minute', type: 'smallint')]
    private readonly int $endsAtMinute;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        User $coach,
        int $dayOfWeek,
        int $startsAtMinute,
        int $endsAtMinute,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->coach = $coach;
        $this->dayOfWeek = $dayOfWeek;
        $this->startsAtMinute = $startsAtMinute;
        $this->endsAtMinute = $endsAtMinute;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCoach(): User
    {
        return $this->coach;
    }

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function getStartsAtMinute(): int
    {
        return $this->startsAtMinute;
    }

    public function getEndsAtMinute(): int
    {
        return $this->endsAtMinute;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
