<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountDeletionLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The GDPR compliance record for one deletion (AC-21). `subjectUser` is
 * UNIQUE: exactly one row can ever exist per account, which is what lets
 * AccountLifecycleService::delete() treat "does a log row already exist" as
 * the authoritative, DB-enforced check for AC-23's "already deleted, refuse"
 * rule -- not merely a re-check of the in-memory status.
 *
 * Deliberately does not keep a backup of the erased PII beyond the
 * pre-anonymization email (see the architecture doc's G-14 resolution):
 * accountability requires who/when/why, not a shadow copy of what was erased.
 */
#[ORM\Entity(repositoryClass: AccountDeletionLogRepository::class)]
#[ORM\Table(name: 'account_deletion_log')]
#[ORM\UniqueConstraint(name: 'uniq_account_deletion_log_subject', columns: ['subject_user_id'])]
class AccountDeletionLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private readonly User $subjectUser;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private readonly ?User $actorUser;

    #[ORM\Column(name: 'anonymized_email', type: 'string', length: 180)]
    private readonly string $anonymizedEmail;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private readonly ?string $reference;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $deletedAt;

    public function __construct(
        User $subjectUser,
        ?User $actorUser,
        string $anonymizedEmail,
        ?string $reference,
        \DateTimeImmutable $deletedAt,
    ) {
        $this->id = new UuidV7();
        $this->subjectUser = $subjectUser;
        $this->actorUser = $actorUser;
        $this->anonymizedEmail = $anonymizedEmail;
        $this->reference = $reference;
        $this->deletedAt = $deletedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubjectUser(): User
    {
        return $this->subjectUser;
    }

    public function getActorUser(): ?User
    {
        return $this->actorUser;
    }

    public function getAnonymizedEmail(): string
    {
        return $this->anonymizedEmail;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
