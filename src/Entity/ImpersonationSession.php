<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ImpersonationEndReason;
use App\Repository\ImpersonationSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One Super Admin impersonation session (S6, AC-10). The mutable authority
 * for "is this actor impersonating right now, and when does it expire" --
 * see the architecture's Approach #2 and D2. `AccountEventType`'s
 * `IMPERSONATION_STARTED`/`IMPERSONATION_ENDED` cases mirror the same two
 * moments into the unified account timeline; this row is not replaced by
 * them (D2b).
 *
 * `endedAt IS NULL` *is* "active right now" (NFR-001) -- there is no
 * separate boolean. `expiresAt` is stored, not computed from
 * `startedAt + %app.impersonation_ttl_seconds%`, so a later change to that
 * parameter cannot retroactively expire or extend a session that was
 * already granted a stated deadline (D4c). Both FKs are `RESTRICT`, matching
 * `account_event`'s own reasoning: a compliance record must not vanish when
 * an account is removed, since S2's deletion path anonymizes `app_user` rows
 * in place rather than deleting them.
 *
 * **No `duration_seconds` column** -- duration is `endedAt - startedAt`,
 * computed here and rendered by the report template; a stored copy would be
 * a second source of truth nothing sorts or filters by. **No `session_id`
 * or session-hash column** -- the open row is keyed by actor, and the
 * partial unique index `uniq_impersonation_active_actor` (migration) is what
 * guarantees at most one open row per actor; binding to a PHP session id
 * would break the moment that id rotated.
 *
 * `hasExpired()` takes "now" as an argument rather than reading the clock
 * itself, per the architecture's Risks entry ("time is compared in one
 * place and must stay there") -- every comparison goes through
 * `ImpersonationService`, and this method is what keeps that testable.
 */
#[ORM\Entity(repositoryClass: ImpersonationSessionRepository::class)]
#[ORM\Table(name: 'impersonation_session')]
#[ORM\Index(name: 'idx_impersonation_session_actor_started', columns: ['actor_user_id', 'started_at'])]
#[ORM\Index(name: 'idx_impersonation_session_subject_started', columns: ['subject_user_id', 'started_at'])]
#[ORM\UniqueConstraint(name: 'uniq_impersonation_active_actor', columns: ['actor_user_id'], options: ['where' => '(ended_at IS NULL)'])]
class ImpersonationSession
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private readonly User $actorUser;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private readonly User $subjectUser;

    #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'ended_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'end_reason', type: 'string', length: 24, nullable: true, enumType: ImpersonationEndReason::class)]
    private ?ImpersonationEndReason $endReason = null;

    #[ORM\Column(name: 'actor_ip', type: 'inet', nullable: true)]
    private readonly ?string $actorIp;

    public function __construct(
        User $actorUser,
        User $subjectUser,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $expiresAt,
        ?string $actorIp = null,
    ) {
        $this->id = new UuidV7();
        $this->actorUser = $actorUser;
        $this->subjectUser = $subjectUser;
        $this->startedAt = $startedAt;
        $this->expiresAt = $expiresAt;
        $this->actorIp = $actorIp;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActorUser(): User
    {
        return $this->actorUser;
    }

    public function getSubjectUser(): User
    {
        return $this->subjectUser;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getEndReason(): ?ImpersonationEndReason
    {
        return $this->endReason;
    }

    public function getActorIp(): ?string
    {
        return $this->actorIp;
    }

    public function isOpen(): bool
    {
        return null === $this->endedAt;
    }

    /**
     * Only `ImpersonationSessionRepository::closeIfOpen()`'s conditional
     * `UPDATE` is the actual source of truth for "closed exactly once"
     * (D4b) -- this setter is what an already-loaded entity reflects after
     * that statement runs, or what a fresh insert-then-close in the same
     * unit of work would use. It is not itself a race-safe close.
     */
    public function markEnded(\DateTimeImmutable $endedAt, ImpersonationEndReason $reason): void
    {
        $this->endedAt = $endedAt;
        $this->endReason = $reason;
    }

    /**
     * `null` while the session is still open -- there is nothing to
     * compute a duration over yet (AC-14).
     */
    public function getDuration(): ?\DateInterval
    {
        if (null === $this->endedAt) {
            return null;
        }

        return $this->startedAt->diff($this->endedAt);
    }

    /**
     * @param \DateTimeImmutable $now supplied by the caller, never read from
     *                                the clock here -- see the class docblock
     */
    public function hasExpired(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }
}
