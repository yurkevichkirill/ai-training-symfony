<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;

/**
 * Object-mother builders for the account states the S1 tests need.
 *
 * Deliberately not Foundry: it is not a project dependency, and this slice
 * needs three fixed combinations rather than a general-purpose factory.
 *
 * Every user gets a real Argon2id hash of PASSWORD, so functional tests sign
 * in through the actual authenticator rather than faking a token. Argon2id is
 * intentionally expensive, so the hash is computed once per process and reused
 * -- hashing it per test would add seconds to the suite for no coverage.
 */
final class UserFactory
{
    /**
     * The known plaintext for every user this factory builds. Twelve
     * characters, so it satisfies the S1 password policy (Task 25) and can be
     * reused as the "current password" in reset-flow tests.
     */
    public const PASSWORD = 'a-valid-test-password-12';

    /**
     * Must mirror security.yaml's `when@test` password_hashers block. A fixture
     * hashed at production strength while the app's test hasher is configured
     * cheap makes verification of a *real* account cost ~40x what
     * LoginTimingPaddingSubscriber's padding costs -- which shows up as a huge
     * fake timing signal in SignInTest and hides whether the padding actually
     * works. Keep these two in sync.
     */
    private const HASH_OPTIONS = ['time_cost' => 3, 'memory_cost' => 10];

    private static ?string $passwordHash = null;

    private static int $sequence = 0;

    public static function activeVerified(UserRole $role, ?string $email = null): User
    {
        $user = self::build($role, UserStatus::ACTIVE, $email);
        $user->markEmailVerified(new \DateTimeImmutable('-1 day'));

        return $user;
    }

    public static function activeUnverified(UserRole $role, ?string $email = null): User
    {
        return self::build($role, UserStatus::ACTIVE, $email);
    }

    /**
     * Deactivated *and* verified: a deactivated account must be refused for
     * being deactivated, not incidentally for being unverified, or the test
     * would pass for the wrong reason.
     */
    public static function deactivated(UserRole $role, ?string $email = null): User
    {
        $user = self::build($role, UserStatus::DEACTIVATED, $email);
        $user->markEmailVerified(new \DateTimeImmutable('-1 day'));

        return $user;
    }

    /**
     * The Argon2id hash of PASSWORD, computed once per process.
     */
    public static function passwordHash(): string
    {
        return self::$passwordHash ??= password_hash(self::PASSWORD, \PASSWORD_ARGON2ID, self::HASH_OPTIONS);
    }

    /**
     * A unique, already-normalized address, so that tests persisting several
     * users never collide on UNIQUE (email).
     */
    public static function email(string $prefix = 'user'): string
    {
        return \sprintf('%s%d@example.test', $prefix, ++self::$sequence);
    }

    private static function build(UserRole $role, UserStatus $status, ?string $email): User
    {
        return new User(
            $email ?? self::email(strtolower(str_replace('ROLE_', '', $role->value))),
            self::passwordHash(),
            $role,
            $status,
        );
    }
}
