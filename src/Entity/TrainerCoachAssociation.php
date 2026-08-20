<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainerCoachAssociationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One coach connected to one trainer (AC-15, AC-16, AC-17). `endedAt` is
 * null while the association is active; the partial unique index below is
 * AC-16's exclusivity rule -- a coach cannot be *actively* associated with
 * two trainers at once -- stated once, in the only place that cannot be
 * raced past: `CoachInvitationService::accept()`'s pre-check and this index
 * agree, and a caught `UniqueConstraintViolationException` on it becomes the
 * same typed refusal the pre-check would have raised. Its predicate is
 * exactly the resolved edge case's wording: an ended row is invisible to the
 * constraint, so "ended with Trainer A, accepts Trainer B" succeeds
 * *because of* the index, not despite it.
 *
 * **No S3 code path writes `endedAt`.** The column exists only so AC-16's
 * rule and that edge case have something to be defined against (architecture
 * Decisions Q2'); the action that ends an association belongs to the later
 * roster-management slice.
 */
#[ORM\Entity(repositoryClass: TrainerCoachAssociationRepository::class)]
#[ORM\Table(name: 'trainer_coach_association')]
#[ORM\UniqueConstraint(name: 'uniq_trainer_coach_active_coach', columns: ['coach_id'], options: ['where' => '(ended_at IS NULL)'])]
#[ORM\Index(name: 'idx_trainer_coach_association_trainer_ended', columns: ['trainer_id', 'ended_at'])]
#[ORM\Index(name: 'idx_trainer_coach_association_coach_ended', columns: ['coach_id', 'ended_at'])]
class TrainerCoachAssociation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'trainer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $trainer;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'coach_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $coach;

    #[ORM\ManyToOne(targetEntity: CoachInvitation::class)]
    #[ORM\JoinColumn(name: 'invitation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private readonly ?CoachInvitation $invitation;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'ended_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(
        User $trainer,
        User $coach,
        ?CoachInvitation $invitation,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->trainer = $trainer;
        $this->coach = $coach;
        $this->invitation = $invitation;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrainer(): User
    {
        return $this->trainer;
    }

    public function getCoach(): User
    {
        return $this->coach;
    }

    public function getInvitation(): ?CoachInvitation
    {
        return $this->invitation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function isActive(): bool
    {
        return null === $this->endedAt;
    }
}
