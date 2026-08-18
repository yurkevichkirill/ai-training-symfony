<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Enum\UserRole;
use App\Security\AccountStatusChecker;
use App\Security\Exception\AccountDeactivatedException;
use App\Security\Exception\EmailNotVerifiedException;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AccountStatusCheckerTest extends TestCase
{
    private AccountStatusChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new AccountStatusChecker();
    }

    #[DataProvider('roles')]
    public function testDeactivatedAccountIsRefused(UserRole $role): void
    {
        $this->expectException(AccountDeactivatedException::class);

        $this->checker->checkPostAuth(UserFactory::deactivated($role));
    }

    #[DataProvider('roles')]
    public function testUnverifiedAccountIsRefused(UserRole $role): void
    {
        $this->expectException(EmailNotVerifiedException::class);

        $this->checker->checkPostAuth(UserFactory::activeUnverified($role));
    }

    #[DataProvider('roles')]
    public function testActiveVerifiedAccountPasses(UserRole $role): void
    {
        $this->checker->checkPostAuth(UserFactory::activeVerified($role));

        $this->expectNotToPerformAssertions();
    }

    /**
     * Deactivation is reported ahead of verification, so a deactivated account
     * never leaks the additional fact that it was also unverified. The two
     * exceptions are distinguishable server-side (for the audit trail) but the
     * ordering keeps that distinction from becoming a second signal.
     */
    public function testDeactivationIsReportedBeforeVerificationWhenBothApply(): void
    {
        $user = UserFactory::activeUnverified(UserRole::PLAYER);
        $user->setStatus(\App\Enum\UserStatus::DEACTIVATED);

        $this->expectException(AccountDeactivatedException::class);

        $this->checker->checkPostAuth($user);
    }

    /**
     * checkPreAuth must stay empty: running these checks before the password is
     * verified would answer "does this account exist, and in what state" to
     * anyone who types an address with any password at all.
     */
    #[DataProvider('roles')]
    public function testCheckPreAuthNeverRefusesAnything(UserRole $role): void
    {
        $this->checker->checkPreAuth(UserFactory::deactivated($role));
        $this->checker->checkPreAuth(UserFactory::activeUnverified($role));

        $this->expectNotToPerformAssertions();
    }

    /**
     * The checker is registered firewall-wide, so it must tolerate a user class
     * it does not know rather than fataling on it.
     */
    public function testForeignUserClassesAreIgnored(): void
    {
        $this->checker->checkPostAuth(new InMemoryUser('someone', null));

        $this->expectNotToPerformAssertions();
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
