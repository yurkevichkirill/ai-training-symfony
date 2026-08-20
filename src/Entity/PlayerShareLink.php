<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerShareLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A trainer's permanent, broadcastable player-invite link (AC-1, AC-2, AC-4).
 *
 * `UNIQUE (trainer_id)` -- not just `UNIQUE (code)` -- is the database fact
 * that makes "one link per trainer" true: it is what
 * `PlayerShareLinkService::getOrCreateFor()` resolves a concurrent
 * double-generate against, and what keeps AC-1's "never an ambiguous or
 * different trainer" true regardless of how many times a trainer asks for
 * their link. Deliberately **no** `expires_at`, `max_uses`, or
 * `consumed_at` column: AC-2 is "no expiry, no maximum-use count", which an
 * absent column represents as an absence, not a nullable one some future
 * code path could set (architecture Decisions Q1a).
 *
 * The `code` itself is stored in plaintext, not hashed like
 * `AccountInvitation`'s verifier: the trainer must be able to re-display and
 * broadcast it indefinitely, so hashing it at rest would hide it from its
 * own owner (Decisions Q1a').
 */
#[ORM\Entity(repositoryClass: PlayerShareLinkRepository::class)]
#[ORM\Table(name: 'player_share_link')]
#[ORM\UniqueConstraint(name: 'uniq_player_share_link_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_player_share_link_trainer', columns: ['trainer_id'])]
class PlayerShareLink
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'trainer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $trainer;

    #[ORM\Column(type: 'string', length: 24)]
    private readonly string $code;

    #[ORM\Column(name: 'usage_count', type: 'integer', options: ['default' => 0])]
    private int $usageCount = 0;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(User $trainer, string $code, ?\DateTimeImmutable $now = null)
    {
        $this->id = new UuidV7();
        $this->trainer = $trainer;
        $this->code = $code;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrainer(): User
    {
        return $this->trainer;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    /**
     * AC-6's lifetime tally: "the number of times a given player ShareLink
     * has been used", a monotonic counter that must never decrease.
     *
     * **No production write path calls this method any more (Task 32
     * hardening fix).** Mutating this property in PHP and letting the
     * UnitOfWork flush the resulting value as a literal `UPDATE ... SET
     * usage_count = :value` is exactly the lost-update race that fix
     * removed: two concurrent registrations against the same link could
     * both read `usage_count = 0`, both call this method, and both flush a
     * literal `1`, silently losing one increment. `PlayerShareLinkService::
     * associate()` and `PlayerRegistrationService::registerViaShareLink()`
     * now issue an atomic, database-computed `UPDATE player_share_link SET
     * usage_count = usage_count + 1 WHERE id = :id` directly instead. This
     * method remains only for direct, single-connection entity-level tests
     * (see `ShareLinkInvitationsConstraintsTest`) where that race cannot
     * occur.
     */
    public function incrementUsage(): void
    {
        ++$this->usageCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
