<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CoachInvitationStatus;
use App\Repository\CoachInvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A trainer's single-use, seven-day invitation to a not-yet-existing Coach
 * account, addressed by email (AC-3, AC-5, AC-17, AC-18). Same
 * selector/verifier discipline as `AccountInvitation`, but a second table
 * rather than a nullable retrofit of it (architecture Decisions Q1b):
 * `AccountInvitation.user_id` is NOT NULL and its whole lifecycle assumes an
 * account that already exists, which is never true here.
 *
 * `status()` derives Pending/Accepted/Expired from `acceptedAt`/`expiresAt`
 * rather than a stored column (Decisions Q1b'): Expired is purely a function
 * of the clock.
 *
 * `invitedEmail` is stored already normalized (through
 * `User::normalizeEmail()`, the single normalization point -- reused here,
 * not re-implemented) and the migration's hand-written
 * `CHECK (invited_email = lower(invited_email))` makes an unnormalized value
 * unstorable even if some future caller bypasses that.
 *
 * Deliberately **no** unique constraint on `(trainer_id, invited_email)`:
 * AC-18 requires re-inviting the same person to remain legal.
 */
#[ORM\Entity(repositoryClass: CoachInvitationRepository::class)]
#[ORM\Table(name: 'coach_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_coach_invitation_selector', columns: ['selector'])]
#[ORM\Index(name: 'idx_coach_invitation_trainer_created', columns: ['trainer_id', 'created_at'])]
#[ORM\Index(name: 'idx_coach_invitation_email_accepted', columns: ['invited_email', 'accepted_at'])]
class CoachInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'trainer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $trainer;

    #[ORM\Column(name: 'invited_email', type: 'string', length: 180)]
    private readonly string $invitedEmail;

    #[ORM\Column(name: 'invited_name', type: 'string', length: 160, nullable: true)]
    private readonly ?string $invitedName;

    #[ORM\Column(type: 'text', nullable: true)]
    private readonly ?string $message;

    #[ORM\Column(type: 'string', length: 24)]
    private readonly string $selector;

    #[ORM\Column(name: 'hashed_verifier', type: 'string', length: 64, options: ['fixed' => true])]
    private readonly string $hashedVerifier;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'accepted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        User $trainer,
        string $invitedEmail,
        ?string $invitedName,
        ?string $message,
        string $selector,
        string $hashedVerifier,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->trainer = $trainer;
        $this->invitedEmail = $invitedEmail;
        $this->invitedName = $invitedName;
        $this->message = $message;
        $this->selector = $selector;
        $this->hashedVerifier = $hashedVerifier;
        $this->expiresAt = $expiresAt;
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

    public function getInvitedEmail(): string
    {
        return $this->invitedEmail;
    }

    public function getInvitedName(): ?string
    {
        return $this->invitedName;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getHashedVerifier(): string
    {
        return $this->hashedVerifier;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function isAccepted(): bool
    {
        return null !== $this->acceptedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function accept(\DateTimeImmutable $at): void
    {
        $this->acceptedAt ??= $at;
    }

    /**
     * AC-17's Pending/Accepted/Expired, derived rather than stored
     * (architecture Decisions Q1b'). Accepted takes priority over the clock:
     * an invitation accepted before its deadline stays Accepted forever, it
     * does not revert to Expired.
     */
    public function status(\DateTimeImmutable $now): CoachInvitationStatus
    {
        if (null !== $this->acceptedAt) {
            return CoachInvitationStatus::ACCEPTED;
        }

        if ($this->isExpired($now)) {
            return CoachInvitationStatus::EXPIRED;
        }

        return CoachInvitationStatus::PENDING;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
