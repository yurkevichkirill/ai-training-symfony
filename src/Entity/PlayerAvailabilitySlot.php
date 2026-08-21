<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerAvailabilitySlotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One weekly-recurring availability range for a player -- adult or child
 * alike (AC-23); `player` is `app_user` directly, not a
 * (player, trainer) pairing (D5d: one grid per player, visible to every
 * trainer that player is connected to, never answered once per connection).
 *
 * **No `is_unavailable` flag and no row-per-day placeholder.** The absence
 * of any row for a given weekday *is* "Not Available" (AC-24) -- a rule the
 * roster-filter join (`TrainerPlayerAssociationRepository::findRosterAvailableAt()`)
 * enforces mechanically via `INNER JOIN`, not something any caller has to
 * remember. **No time-zone column**: the spec puts time zones out of scope,
 * so every value here is facility-local wall-clock time, and the absence of
 * a column keeps that assumption visible instead of silently defaulted.
 *
 * `dayOfWeek` is ISO-8601, Monday = 1 ... Sunday = 7 (`date('N')`).
 * `startsAtMinute`/`endsAtMinute` are minutes from local midnight; 1440
 * ("midnight at the end of the day") is the only legal value for
 * `endsAtMinute` that equals the day's length. Hand-written CHECK
 * constraints enforce both ranges and `starts < ends` at the storage level.
 *
 * `AvailabilityService::replaceWeek()` is the only writer: one transaction
 * deletes every row for the player and inserts the normalized replacement
 * set (`PlayerAvailabilitySlotRepository::replaceWeekFor()`), so there is
 * never a partial or stale row left over from a previous save.
 */
#[ORM\Entity(repositoryClass: PlayerAvailabilitySlotRepository::class)]
#[ORM\Table(name: 'player_availability_slot')]
#[ORM\Index(name: 'idx_player_availability_slot_player_day_start', columns: ['player_id', 'day_of_week', 'starts_at_minute'])]
#[ORM\Index(name: 'idx_player_availability_slot_day_start_end', columns: ['day_of_week', 'starts_at_minute', 'ends_at_minute'])]
class PlayerAvailabilitySlot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $player;

    #[ORM\Column(name: 'day_of_week', type: 'smallint')]
    private readonly int $dayOfWeek;

    #[ORM\Column(name: 'starts_at_minute', type: 'smallint')]
    private readonly int $startsAtMinute;

    #[ORM\Column(name: 'ends_at_minute', type: 'smallint')]
    private readonly int $endsAtMinute;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        User $player,
        int $dayOfWeek,
        int $startsAtMinute,
        int $endsAtMinute,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->player = $player;
        $this->dayOfWeek = $dayOfWeek;
        $this->startsAtMinute = $startsAtMinute;
        $this->endsAtMinute = $endsAtMinute;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlayer(): User
    {
        return $this->player;
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
