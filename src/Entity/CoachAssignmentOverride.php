<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AvailabilityCoverage;
use App\Repository\CoachAssignmentOverrideRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * An append-only audit record (AC-7, AC-8): which coach's availability was
 * overridden, which trainer overrode it, the candidate time that
 * conflicted, the coverage that was evaluated at that moment, the required
 * reason, and when. Self-describing with no event in existence -- storing
 * the candidate day/start/end is what makes Epic-02's future `event_id` a
 * pure *narrowing* addition rather than something this row depends on
 * (D3, D3b).
 *
 * **No unique constraint of any kind** -- two rapid overrides for the same
 * coach/trainer pair must both persist (edge case 4); this is a log, not a
 * mutable "current override" state. **No `event_id` column** (D3b): Epic-02
 * adds it later as a pure narrowing addition.
 *
 * Both FKs are `RESTRICT`, not `CASCADE` -- the same choice S2 made for
 * `account_event.subject_user_id`: a compliance record must not vanish when
 * an account is removed.
 *
 * `CoachAssignmentOverrideService::record()` is the only writer, and this
 * slice ships **no route, no form, and no console command** that calls it
 * (D3c) -- a writer with no real conflict behind it would be a forgery
 * primitive, not a test harness. This class has no production caller by
 * design; Epic-02 is the intended one.
 */
#[ORM\Entity(repositoryClass: CoachAssignmentOverrideRepository::class)]
#[ORM\Table(name: 'coach_assignment_override')]
#[ORM\Index(name: 'idx_coach_assignment_override_coach_created', columns: ['coach_id', 'created_at'])]
#[ORM\Index(name: 'idx_coach_assignment_override_overridden_by_created', columns: ['overridden_by_user_id', 'created_at'])]
class CoachAssignmentOverride
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'coach_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private readonly User $coach;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'overridden_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private readonly User $overriddenByUser;

    #[ORM\Column(name: 'day_of_week', type: 'smallint')]
    private readonly int $dayOfWeek;

    #[ORM\Column(name: 'starts_at_minute', type: 'smallint')]
    private readonly int $startsAtMinute;

    #[ORM\Column(name: 'ends_at_minute', type: 'smallint')]
    private readonly int $endsAtMinute;

    #[ORM\Column(type: 'string', length: 24, enumType: AvailabilityCoverage::class)]
    private readonly AvailabilityCoverage $coverage;

    #[ORM\Column(type: 'text')]
    private readonly string $reason;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        User $coach,
        User $overriddenByUser,
        int $dayOfWeek,
        int $startsAtMinute,
        int $endsAtMinute,
        AvailabilityCoverage $coverage,
        string $reason,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->coach = $coach;
        $this->overriddenByUser = $overriddenByUser;
        $this->dayOfWeek = $dayOfWeek;
        $this->startsAtMinute = $startsAtMinute;
        $this->endsAtMinute = $endsAtMinute;
        $this->coverage = $coverage;
        $this->reason = $reason;
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

    public function getOverriddenByUser(): User
    {
        return $this->overriddenByUser;
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

    public function getCoverage(): AvailabilityCoverage
    {
        return $this->coverage;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
