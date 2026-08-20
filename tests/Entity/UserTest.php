<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for `User`'s S2 additions: the anonymization transform
 * (AC-19, AC-22) and the display-name fallback FR-011 needs before a name
 * is ever set.
 */
final class UserTest extends TestCase
{
    public function testGetDisplayNameFallsBackToTheEmailLocalPartWhenNoNameIsSet(): void
    {
        $user = new User('dana@example.test', 'hash', UserRole::TRAINER);

        self::assertSame('dana', $user->getDisplayName());
    }

    public function testGetDisplayNamePrefersTheSetName(): void
    {
        $user = new User('dana@example.test', 'hash', UserRole::TRAINER);
        $user->setName('Dana', 'Trainer');

        self::assertSame('Dana Trainer', $user->getDisplayName());
    }

    /**
     * AC-19: a deleted account must never render its anonymized
     * `deleted_<uuid>@example.com` local part (or any PII it held before
     * deletion) as its display name.
     */
    public function testGetDisplayNameReturnsDeletedUserRegardlessOfPriorName(): void
    {
        $user = new User('dana@example.test', 'hash', UserRole::TRAINER);
        $user->setName('Dana', 'Trainer');

        $user->anonymize(new \DateTimeImmutable());

        self::assertSame('Deleted User', $user->getDisplayName());
    }

    public function testAnonymizeClearsPiiAndProducesADeterministicUniqueEmail(): void
    {
        $user = new User('real.person@example.test', 'hash', UserRole::PLAYER);
        $user->setName('Real', 'Person');
        $user->setPhone('+15550001111');
        $user->setPhotoKey('photos/abc.png');

        $user->anonymize(new \DateTimeImmutable());

        self::assertNull($user->getFirstName());
        self::assertNull($user->getLastName());
        self::assertNull($user->getPhone());
        self::assertNull($user->getPhotoKey());
        self::assertSame(\sprintf('deleted_%s@example.com', $user->getId()), $user->getEmail());
        self::assertSame(UserStatus::DELETED, $user->getStatus());
    }

    /**
     * The anonymized email can never collide across two different users,
     * because it is derived from each account's own immutable id (G-15).
     */
    public function testAnonymizedEmailsOfTwoDifferentUsersNeverCollide(): void
    {
        $first = new User('one@example.test', 'hash', UserRole::PLAYER);
        $second = new User('two@example.test', 'hash', UserRole::PLAYER);

        $now = new \DateTimeImmutable();
        $first->anonymize($now);
        $second->anonymize($now);

        self::assertNotSame($first->getEmail(), $second->getEmail());
    }
}
