<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One administrative account-management thing that happened (S2) -- see
 * `App\Enum\AccountEventType`. Write-once, same shape and rationale as
 * `AuthEvent` (a table, not a log channel, because S6 reports over this).
 *
 * `subjectUser` is ON DELETE RESTRICT, not SET NULL: deletion anonymizes the
 * `app_user` row in place rather than removing it (see the architecture
 * doc's Approach #4), so the FK target always still exists, and RESTRICT is a
 * second guarantee nothing can quietly change that.
 */
#[ORM\Entity(repositoryClass: AccountEventRepository::class)]
#[ORM\Table(name: 'account_event')]
#[ORM\Index(name: 'idx_account_event_subject_occurred', columns: ['subject_user_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_account_event_actor_occurred', columns: ['actor_user_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_account_event_type_occurred', columns: ['type', 'occurred_at'])]
class AccountEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\Column(name: 'occurred_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'string', length: 64)]
    private readonly string $type;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private readonly ?User $actorUser;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private readonly User $subjectUser;

    #[ORM\Column(type: 'inet', nullable: true)]
    private readonly ?string $ip;

    #[ORM\Column(name: 'user_agent', type: 'string', length: 255, nullable: true)]
    private readonly ?string $userAgent;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private readonly array $context;

    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        string $type,
        \DateTimeImmutable $occurredAt,
        ?User $actorUser,
        User $subjectUser,
        ?string $ip = null,
        ?string $userAgent = null,
        array $context = [],
    ) {
        $this->id = new UuidV7();
        $this->type = $type;
        $this->occurredAt = $occurredAt;
        $this->actorUser = $actorUser;
        $this->subjectUser = $subjectUser;
        $this->ip = $ip;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->context = $context;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getActorUser(): ?User
    {
        return $this->actorUser;
    }

    public function getSubjectUser(): User
    {
        return $this->subjectUser;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
