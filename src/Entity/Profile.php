<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The frozen User<->Profile contract from `specs/auth-foundation-architecture.md`
 * (G-23, AC-15): a Profile carries capability data for one role a User plays,
 * never authority. No voter, no access_control rule, no getRoles()
 * implementation may ever read a Profile -- authorization reads User::role
 * only.
 *
 * JOINED inheritance: base table `profile` (this class) plus one table per
 * concrete subtype (`profile_trainer`, later `profile_coach`/`profile_player`/
 * `profile_child`). UNIQUE(user_id, type) is what makes "one profile per type
 * per user, many types per user" (a parent who also plays) an enforced fact,
 * not an aspiration.
 */
#[ORM\Entity(repositoryClass: ProfileRepository::class)]
#[ORM\Table(name: 'profile')]
#[ORM\UniqueConstraint(name: 'uniq_profile_user_type', columns: ['user_id', 'type'])]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', length: 32)]
#[ORM\DiscriminatorMap(['TRAINER' => ProfileTrainer::class, 'PLAYER' => ProfilePlayer::class, 'COACH' => ProfileCoach::class])]
abstract class Profile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    protected readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected readonly User $user;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    protected readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    protected \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    protected ?\DateTimeImmutable $deletedAt = null;

    public function __construct(User $user, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();

        $this->id = new UuidV7();
        $this->user = $user;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(?\DateTimeImmutable $at = null): void
    {
        $this->updatedAt = $at ?? new \DateTimeImmutable();
    }
}
