<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountInvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A single-use invitation for a Super-Admin-created account (currently:
 * trainers, AC-4/AC-5) to set its first password. Same selector/verifier
 * discipline as `EmailVerificationToken` -- see `SelectorVerifierTokenFactory`.
 */
#[ORM\Entity(repositoryClass: AccountInvitationRepository::class)]
#[ORM\Table(name: 'account_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_account_invitation_selector', columns: ['selector'])]
#[ORM\Index(name: 'idx_account_invitation_user_consumed', columns: ['user_id', 'consumed_at'])]
class AccountInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $user;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'issued_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private readonly ?User $issuedBy;

    #[ORM\Column(type: 'string', length: 24)]
    private readonly string $selector;

    #[ORM\Column(name: 'hashed_verifier', type: 'string', length: 64, options: ['fixed' => true])]
    private readonly string $hashedVerifier;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'consumed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    public function __construct(
        User $user,
        ?User $issuedBy,
        string $selector,
        string $hashedVerifier,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->user = $user;
        $this->issuedBy = $issuedBy;
        $this->selector = $selector;
        $this->hashedVerifier = $hashedVerifier;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getIssuedBy(): ?User
    {
        return $this->issuedBy;
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

    public function isConsumed(): bool
    {
        return null !== $this->consumedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function consume(\DateTimeImmutable $at): void
    {
        $this->consumedAt ??= $at;
    }
}
