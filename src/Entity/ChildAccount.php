<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChildAccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The row whose *existence* is FR-024's "Child vs Self marker" -- linking a
 * child's own `User`/`ProfilePlayer` identity to the parent account that
 * created it (AC-2). Its *deletion* is the entire age-18 transition the spec
 * defers: the child keeps its `User`, its `ProfilePlayer`, its associations,
 * and its availability, and simply stops being someone's child. Nothing else
 * in this design encodes childhood.
 *
 * `UNIQUE (child_user_id)` is what makes "is this a child?" a single-row
 * lookup (`ChildAccountResolver`) and what refuses a second parent for one
 * child. **Deliberately no unique constraint on `parent_user_id`** -- that
 * absence is AC-6, "a parent can create more than one child profile".
 * Hand-written `CHECK (child_user_id <> parent_user_id)` so an account can
 * never parent itself.
 */
#[ORM\Entity(repositoryClass: ChildAccountRepository::class)]
#[ORM\Table(name: 'child_account')]
#[ORM\UniqueConstraint(name: 'uniq_child_account_child_user', columns: ['child_user_id'])]
#[ORM\Index(name: 'idx_child_account_parent_created', columns: ['parent_user_id', 'created_at'])]
class ChildAccount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'child_user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private readonly User $childUser;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'parent_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $parentUser;

    #[ORM\Column(name: 'sign_in_enabled_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $signInEnabledAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        User $childUser,
        User $parentUser,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = new UuidV7();
        $this->childUser = $childUser;
        $this->parentUser = $parentUser;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getChildUser(): User
    {
        return $this->childUser;
    }

    public function getParentUser(): User
    {
        return $this->parentUser;
    }

    public function getSignInEnabledAt(): ?\DateTimeImmutable
    {
        return $this->signInEnabledAt;
    }

    public function isSignInEnabled(): bool
    {
        return null !== $this->signInEnabledAt;
    }

    /**
     * `ChildAccountService::enableSignIn()`'s one write path (D1d).
     */
    public function enableSignIn(\DateTimeImmutable $at): void
    {
        $this->signInEnabledAt ??= $at;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
