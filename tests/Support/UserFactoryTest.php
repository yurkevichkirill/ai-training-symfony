<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the builders themselves: every test from here on trusts that
 * "activeVerified" really is active and verified, so a silent regression here
 * would make later tests pass for the wrong reason.
 */
final class UserFactoryTest extends TestCase
{
    #[DataProvider('roles')]
    public function testActiveVerifiedIsActiveAndVerified(UserRole $role): void
    {
        $user = UserFactory::activeVerified($role);

        self::assertSame(UserStatus::ACTIVE, $user->getStatus());
        self::assertNotNull($user->getEmailVerifiedAt());
        self::assertTrue($user->isEmailVerified());
        self::assertSame([$role->value], $user->getRoles());
    }

    #[DataProvider('roles')]
    public function testActiveUnverifiedIsActiveButNotVerified(UserRole $role): void
    {
        $user = UserFactory::activeUnverified($role);

        self::assertSame(UserStatus::ACTIVE, $user->getStatus());
        self::assertNull($user->getEmailVerifiedAt());
        self::assertFalse($user->isEmailVerified());
    }

    #[DataProvider('roles')]
    public function testDeactivatedIsDeactivatedButVerified(UserRole $role): void
    {
        $user = UserFactory::deactivated($role);

        self::assertSame(UserStatus::DEACTIVATED, $user->getStatus());
        self::assertFalse($user->isActive());
        self::assertTrue(
            $user->isEmailVerified(),
            'A deactivated fixture must be verified, so tests fail it for deactivation and not for verification.',
        );
    }

    public function testThePasswordHashVerifiesAgainstTheKnownPlaintext(): void
    {
        $user = UserFactory::activeVerified(UserRole::PLAYER);

        self::assertTrue(password_verify(UserFactory::PASSWORD, $user->getPassword()));
        self::assertStringStartsWith('$argon2id$', $user->getPassword());
    }

    public function testEmailsAreUniqueAndAlreadyNormalized(): void
    {
        $first = UserFactory::activeVerified(UserRole::COACH)->getEmail();
        $second = UserFactory::activeVerified(UserRole::COACH)->getEmail();

        self::assertNotSame($first, $second);
        self::assertSame(mb_strtolower($first), $first);
    }

    /**
     * @return iterable<string, array{UserRole}>
     */
    public static function roles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            yield $role->value => [$role];
        }
    }
}
