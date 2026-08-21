<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChildTrainerRequestResolution;
use App\Repository\ChildTrainerRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A record of a child clicking a trainer's ShareLink (AC-15) -- created
 * unconditionally, regardless of whether the child is already connected to
 * that trainer (D3, the unconditional-block decision). `parentUser` is
 * snapshotted at request time (who was actually notified), not re-derived
 * from `ChildAccount` on read.
 *
 * The partial unique index
 * `uniq_child_trainer_request_pending (child_user_id, trainer_id) WHERE
 * resolved_at IS NULL` (declared below, pre-parenthesized per S3's proven
 * technique) is what admits at most one pending request per (child,
 * trainer) pair while letting a resolved one be superseded by a fresh
 * click. `lastNotifiedAt` is D3b's 24h re-notification clock: the parent
 * email is only re-sent once this value is more than a day old.
 *
 * `resolvedAt`/`resolution` are both null while pending; the hand-written
 * `CHECK ((resolved_at IS NULL) = (resolution IS NULL))` makes a
 * half-resolved row unstorable.
 */
#[ORM\Entity(repositoryClass: ChildTrainerRequestRepository::class)]
#[ORM\Table(name: 'child_trainer_request')]
#[ORM\UniqueConstraint(name: 'uniq_child_trainer_request_pending', columns: ['child_user_id', 'trainer_id'], options: ['where' => '(resolved_at IS NULL)'])]
#[ORM\Index(name: 'idx_child_trainer_request_parent_resolved_created', columns: ['parent_user_id', 'resolved_at', 'created_at'])]
class ChildTrainerRequest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'child_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $childUser;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'trainer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $trainer;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'parent_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $parentUser;

    #[ORM\ManyToOne(targetEntity: PlayerShareLink::class)]
    #[ORM\JoinColumn(name: 'share_link_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private readonly ?PlayerShareLink $shareLink;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_notified_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $lastNotifiedAt;

    #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: 'string', length: 16, enumType: ChildTrainerRequestResolution::class, nullable: true)]
    private ?ChildTrainerRequestResolution $resolution = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resolved_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedByUser = null;

    public function __construct(
        User $childUser,
        User $trainer,
        User $parentUser,
        ?PlayerShareLink $shareLink,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->childUser = $childUser;
        $this->trainer = $trainer;
        $this->parentUser = $parentUser;
        $this->shareLink = $shareLink;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->lastNotifiedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getChildUser(): User
    {
        return $this->childUser;
    }

    public function getTrainer(): User
    {
        return $this->trainer;
    }

    public function getParentUser(): User
    {
        return $this->parentUser;
    }

    public function getShareLink(): ?PlayerShareLink
    {
        return $this->shareLink;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastNotifiedAt(): \DateTimeImmutable
    {
        return $this->lastNotifiedAt;
    }

    /**
     * D3b's 24h re-notification throttle: `ChildTrainerService::recordBlockedClick()`
     * calls this only when it actually re-sends the parent email.
     */
    public function markNotified(\DateTimeImmutable $at): void
    {
        $this->lastNotifiedAt = $at;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolution(): ?ChildTrainerRequestResolution
    {
        return $this->resolution;
    }

    public function getResolvedByUser(): ?User
    {
        return $this->resolvedByUser;
    }

    public function isPending(): bool
    {
        return null === $this->resolvedAt;
    }

    /**
     * `ChildTrainerService::approveRequest()`/`dismissRequest()`'s one write
     * path. Callers refuse an already-resolved request before calling this
     * (`ChildTrainerRequestAlreadyResolvedException`) rather than relying on
     * this method to be idempotent.
     */
    public function resolve(ChildTrainerRequestResolution $resolution, User $resolvedByUser, \DateTimeImmutable $at): void
    {
        $this->resolution = $resolution;
        $this->resolvedByUser = $resolvedByUser;
        $this->resolvedAt = $at;
    }
}
