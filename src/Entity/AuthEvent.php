<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One authentication-relevant thing that happened (AC-24).
 *
 * Write-once: there are no setters, and every field is readonly, so an audit
 * row cannot be edited after the fact by any code path that can reach the
 * entity.
 *
 * A table rather than a log channel because S6 has to *report* over this
 * history, and reports need queries. `user` is nullable and ON DELETE SET NULL
 * so that deleting an account does not destroy the audit trail, and
 * `identifierAttempted` records the normalized email for failures against
 * accounts that do not exist -- which is exactly the case a log of successful
 * logins would miss.
 *
 * `context` must never carry a password, a token, or a verifier; that is
 * enforced by AuthEventRecorder (Task 34) and tested in Task 35.
 */
#[ORM\Entity(repositoryClass: AuthEventRepository::class)]
#[ORM\Table(name: 'auth_event')]
#[ORM\Index(name: 'idx_auth_event_user_occurred', columns: ['user_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_auth_event_type_occurred', columns: ['type', 'occurred_at'])]
#[ORM\Index(name: 'idx_auth_event_ip_occurred', columns: ['ip', 'occurred_at'])]
class AuthEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\Column(name: 'occurred_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'string', length: 64)]
    private readonly string $type;

    #[ORM\Column(type: 'string', length: 16)]
    private readonly string $outcome;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private readonly ?User $user;

    #[ORM\Column(name: 'identifier_attempted', type: 'string', length: 180, nullable: true)]
    private readonly ?string $identifierAttempted;

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
        string $outcome,
        \DateTimeImmutable $occurredAt,
        ?User $user = null,
        ?string $identifierAttempted = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $context = [],
    ) {
        $this->id = new UuidV7();
        $this->type = $type;
        $this->outcome = $outcome;
        $this->occurredAt = $occurredAt;
        $this->user = $user;
        $this->identifierAttempted = $identifierAttempted;
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

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getIdentifierAttempted(): ?string
    {
        return $this->identifierAttempted;
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
