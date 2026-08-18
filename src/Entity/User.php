<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A platform account.
 *
 * "user" is reserved in PostgreSQL, hence the app_user table.
 *
 * Identifiers are UUIDv7 rather than the project-wide `identity` preference in
 * config/packages/doctrine.yaml: sequential integers leak account counts and
 * invite enumeration, while UUIDv7 keeps inserts index-local. The value is
 * generated in the constructor rather than by a Doctrine ID generator so an
 * entity has its identity before flush -- audit records (AC-24) reference a
 * user that may not have been flushed yet.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: 'string', length: 32, enumType: UserRole::class)]
    private UserRole $role;

    #[ORM\Column(type: 'string', length: 32, enumType: UserStatus::class)]
    private UserStatus $status;

    #[ORM\Column(name: 'email_verified_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(name: 'password_changed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    #[ORM\Column(name: 'last_login_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $email,
        string $passwordHash,
        UserRole $role,
        UserStatus $status = UserStatus::ACTIVE,
        ?\DateTimeImmutable $now = null,
    ) {
        $now ??= new \DateTimeImmutable();

        $this->id = new UuidV7();
        $this->email = self::normalizeEmail($email);
        $this->passwordHash = $passwordHash;
        $this->role = $role;
        $this->status = $status;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * The single normalization point for an email address (AC-5).
     *
     * Every write path goes through here, so no caller can store a variant
     * that would defeat the UNIQUE (email) index. The schema's
     * CHECK (email = lower(email)) makes an unnormalized value unstorable even
     * if some future code bypasses the entity entirely.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = self::normalizeEmail($email);
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash, ?\DateTimeImmutable $changedAt = null): void
    {
        $this->passwordHash = $passwordHash;
        $this->passwordChangedAt = $changedAt ?? new \DateTimeImmutable();
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): void
    {
        $this->role = $role;
    }

    /**
     * @return list<string> exactly one role
     *
     * No ROLE_USER literal here -- it is granted by role_hierarchy, so that the
     * entity stays the single source of the one role the account holds (AC-15).
     */
    public function getRoles(): array
    {
        return [$this->role->value];
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): void
    {
        $this->status = $status;
    }

    public function isActive(): bool
    {
        return UserStatus::ACTIVE === $this->status;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function markEmailVerified(?\DateTimeImmutable $at = null): void
    {
        $this->emailVerifiedAt ??= $at ?? new \DateTimeImmutable();
    }

    public function getPasswordChangedAt(): ?\DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
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

    public function eraseCredentials(): void
    {
        // No plaintext credential is ever held on this object.
    }

    /**
     * Any change to the security-relevant signature invalidates every *other*
     * live session on its next request: the token stored in those sessions no
     * longer equals the freshly loaded user, so Symfony logs them out.
     *
     * Deliberately narrow -- email, timestamps and display data are not part of
     * it, so an unrelated profile edit does not sign everyone out.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->id->equals($user->id)
            && $this->role === $user->role
            && $this->status === $user->status
            && hash_equals($this->passwordHash, $user->passwordHash)
            && $this->emailVerifiedAt?->getTimestamp() === $user->emailVerifiedAt?->getTimestamp();
    }
}
