<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\ImpersonationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The full eligibility truth table for `ImpersonationVoter` (AC-1, AC-3,
 * AC-12, D5): role x active/deactivated x self/other/super-admin-target x
 * plain-vs-`SwitchUserToken`, matching `ShareLinkVoterTest`'s data-provider
 * shape -- plus the explicit assertion that no role in `role_hierarchy`
 * grants `ROLE_ALLOWED_TO_SWITCH` (the mitigation named in the
 * architecture's Risks section).
 */
final class ImpersonationVoterTest extends TestCase
{
    private ImpersonationVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new ImpersonationVoter();
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, UserRole, UserStatus, bool, bool}>
     */
    public static function switchTruthTableCases(): iterable
    {
        foreach (UserRole::cases() as $actorRole) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $actorStatus) {
                foreach (UserRole::cases() as $targetRole) {
                    foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $targetStatus) {
                        foreach ([false, true] as $actorAlreadySwitched) {
                            $granted = UserRole::SUPER_ADMIN === $actorRole
                                && UserStatus::ACTIVE === $actorStatus
                                && !$actorAlreadySwitched
                                && UserRole::SUPER_ADMIN !== $targetRole
                                && UserStatus::ACTIVE === $targetStatus;

                            yield \sprintf(
                                'actor=%s/%s target=%s/%s nested=%s',
                                $actorRole->value,
                                $actorStatus->value,
                                $targetRole->value,
                                $targetStatus->value,
                                $actorAlreadySwitched ? 'yes' : 'no',
                            ) => [$actorRole, $actorStatus, $targetRole, $targetStatus, $actorAlreadySwitched, $granted];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('switchTruthTableCases')]
    public function testRoleAllowedToSwitchTruthTable(
        UserRole $actorRole,
        UserStatus $actorStatus,
        UserRole $targetRole,
        UserStatus $targetStatus,
        bool $actorAlreadySwitched,
        bool $expectedGranted,
    ): void {
        $actor = new User('actor@example.test', 'hash', $actorRole, $actorStatus);
        $target = new User('target@example.test', 'hash', $targetRole, $targetStatus);

        $token = $actorAlreadySwitched ? $this->switchUserTokenFor($actor) : $this->tokenFor($actor);

        $result = $this->voter->vote($token, $target, [ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    public function testASuperAdminIsRefusedToImpersonateTheirOwnAccount(): void
    {
        $admin = new User('admin@example.test', 'hash', UserRole::SUPER_ADMIN);
        $token = $this->tokenFor($admin);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $admin, [ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH]),
        );
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, bool}>
     */
    public static function viewHistoryCases(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $status) {
                $granted = UserRole::SUPER_ADMIN === $role && UserStatus::ACTIVE === $status;
                yield \sprintf('%s / %s', $role->value, $status->value) => [$role, $status, $granted];
            }
        }
    }

    #[DataProvider('viewHistoryCases')]
    public function testViewImpersonationHistoryTruthTable(UserRole $role, UserStatus $status, bool $expectedGranted): void
    {
        $user = new User('viewer@example.test', 'hash', $role, $status);
        $token = $this->tokenFor($user);

        $result = $this->voter->vote($token, null, [ImpersonationVoter::VIEW_IMPERSONATION_HISTORY]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    public function testAbstainsOnAnEntirelyUnknownAttribute(): void
    {
        $user = new User('someone@example.test', 'hash', UserRole::TRAINER);
        $token = $this->tokenFor($user);
        $target = new User('target@example.test', 'hash', UserRole::PLAYER);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, $target, ['SOME_OTHER_ATTRIBUTE']),
        );
    }

    public function testAbstainsWhenRoleAllowedToSwitchIsCheckedAgainstANonUserSubject(): void
    {
        $user = new User('someone@example.test', 'hash', UserRole::SUPER_ADMIN);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, 'not-a-user', [ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH]),
        );
    }

    public function testANonUserTokenSubjectIsDeniedRatherThanAbstainedForASupportedPair(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        $target = new User('target@example.test', 'hash', UserRole::PLAYER);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $target, [ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH]),
        );
    }

    /**
     * The mitigation named in the architecture's Risks section:
     * `ROLE_ALLOWED_TO_SWITCH` in `role_hierarchy` would silently kill
     * BR-002 by letting a Super Admin hold the attribute as a role rather
     * than earning it through this voter. `role_hierarchy` must be flat
     * with respect to it -- no case below inherits or lists it.
     */
    public function testNoRoleInTheRoleHierarchyGrantsRoleAllowedToSwitch(): void
    {
        $securityConfig = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/security.yaml');
        $roleHierarchy = $securityConfig['security']['role_hierarchy'] ?? [];

        self::assertNotEmpty($roleHierarchy, 'Precondition: role_hierarchy must exist to make this assertion meaningful.');

        foreach ($roleHierarchy as $role => $inherited) {
            self::assertNotSame(
                ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH,
                $role,
                \sprintf('%s must never itself be a role_hierarchy key.', ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH),
            );

            $inheritedRoles = \is_array($inherited) ? $inherited : [$inherited];
            self::assertNotContains(
                ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH,
                $inheritedRoles,
                \sprintf('%s must never be inherited via role_hierarchy -- only this voter may grant it.', ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH),
            );
        }
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function switchUserTokenFor(User $user): SwitchUserToken
    {
        $originalToken = $this->tokenFor($user);

        return new SwitchUserToken($user, 'main', $user->getRoles(), $originalToken, '/');
    }
}
