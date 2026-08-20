<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainerPlayerAssociationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One player connected to one trainer (AC-6, AC-8, AC-12, AC-13). `endedAt`
 * is null while the association is active; the partial unique index below
 * replaces the old plain `UNIQUE (trainer_id, player_id)` (Task 36, AC-11
 * amendment -- see the architecture doc's "Post-implementation hardening
 * decisions"), mirroring `TrainerCoachAssociation`'s already-established
 * shape exactly: an ended row is invisible to the constraint, so a player
 * who leaves and later rejoins the same trainer's link gets a fresh row
 * instead of resurrecting a stale one. AC-13's idempotency ("no duplicate
 * association is created") and AC-12's "never duplicated" are still the
 * same database fact this index provides -- just scoped to currently-active
 * rows now, not to the pair unconditionally.
 *
 * `shareLink` is nullable, `ON DELETE SET NULL`: it exists for AC-6's
 * "attributable to the specific link that was used", but Epic-08's camp
 * conversion and any future admin-created association have no link to
 * attribute to.
 *
 * **`PlayerShareLinkService::leave()` is the only writer of `endedAt`.**
 * Ending an association never deletes the row -- it stays as the historical
 * record of that membership, the same "audit trail over hard delete"
 * convention `TrainerCoachAssociation` and `AccountLifecycleService::delete()`
 * already follow.
 */
#[ORM\Entity(repositoryClass: TrainerPlayerAssociationRepository::class)]
#[ORM\Table(name: 'trainer_player_association')]
#[ORM\UniqueConstraint(name: 'uniq_trainer_player_active_association', columns: ['trainer_id', 'player_id'], options: ['where' => '(ended_at IS NULL)'])]
#[ORM\Index(name: 'idx_trainer_player_association_player_created', columns: ['player_id', 'created_at'])]
#[ORM\Index(name: 'idx_trainer_player_association_trainer_created', columns: ['trainer_id', 'created_at'])]
#[ORM\Index(name: 'idx_trainer_player_association_trainer_ended', columns: ['trainer_id', 'ended_at'])]
#[ORM\Index(name: 'idx_trainer_player_association_player_ended', columns: ['player_id', 'ended_at'])]
class TrainerPlayerAssociation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'trainer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $trainer;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $player;

    #[ORM\ManyToOne(targetEntity: PlayerShareLink::class)]
    #[ORM\JoinColumn(name: 'share_link_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private readonly ?PlayerShareLink $shareLink;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'ended_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(
        User $trainer,
        User $player,
        ?PlayerShareLink $shareLink,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->trainer = $trainer;
        $this->player = $player;
        $this->shareLink = $shareLink;
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

    public function getPlayer(): User
    {
        return $this->player;
    }

    public function getShareLink(): ?PlayerShareLink
    {
        return $this->shareLink;
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

    /**
     * `PlayerShareLinkService::leave()`'s one write path. Idempotent by
     * construction of that method's own pre-check (it never calls this on
     * an already-ended row), not by anything enforced here.
     */
    public function end(\DateTimeImmutable $endedAt): void
    {
        $this->endedAt = $endedAt;
    }
}
