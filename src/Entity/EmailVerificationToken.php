<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailVerificationTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A single-use email-verification token, stored under selector/verifier
 * discipline (the same split reset-password-bundle uses).
 *
 * The string handed to the user is `selector . verifier`. Only the selector is
 * indexed, and only a SHA-256 of the verifier is stored, so read access to this
 * table yields nothing that can be redeemed: an attacker with the row still
 * needs the verifier, which exists only in the email that was sent.
 *
 * Single use is enforced by `consumedAt` plus the row lock taken in
 * EmailVerificationService -- see AC-13, AC-14.
 */
#[ORM\Entity(repositoryClass: EmailVerificationTokenRepository::class)]
#[ORM\Table(name: 'email_verification_token')]
#[ORM\UniqueConstraint(name: 'uniq_email_verification_token_selector', columns: ['selector'])]
#[ORM\Index(name: 'idx_email_verification_token_user_consumed', columns: ['user_id', 'consumed_at'])]
class EmailVerificationToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $user;

    #[ORM\Column(type: 'string', length: 24)]
    private readonly string $selector;

    /** SHA-256 of the verifier, hex encoded -- hence exactly 64 characters. */
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
        string $selector,
        string $hashedVerifier,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->user = $user;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function isConsumed(): bool
    {
        return null !== $this->consumedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * Idempotent by construction: a second call cannot move the timestamp, so
     * a replayed request can never look like a fresh consumption.
     */
    public function consume(\DateTimeImmutable $at): void
    {
        $this->consumedAt ??= $at;
    }
}
