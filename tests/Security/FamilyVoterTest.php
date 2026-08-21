<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ChildAccount;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ChildAccountRepository;
use App\Security\FamilyVoter;
use App\Service\ChildAccountResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The full eligibility truth table for `FamilyVoter` (Task 23, AC-2, AC-7,
 * AC-8, AC-9, AC-18): `MANAGE_FAMILY` over every `UserRole` x active/
 * deactivated x child/adult combination, and `MANAGE_CHILD` over
 * parent/non-parent x both accounts' active/deactivated status. Mirrors
 * `ShareLinkVoterTest`'s data-provider-parameterized shape: no kernel, a
 * stubbed `TokenInterface` and a mocked `ChildAccountRepository` stand in
 * for real collaborators.
 */
final class FamilyVoterTest extends TestCase
{
    /**
     * @return array{FamilyVoter, ChildAccountRepository&\PHPUnit\Framework\MockObject\Stub}
     */
    private function voterWithRepository(): array
    {
        $repository = $this->createStub(ChildAccountRepository::class);
        $voter = new FamilyVoter(new ChildAccountResolver($repository));

        return [$voter, $repository];
    }

    /**
     * AC-2, AC-8, AC-18: `MANAGE_FAMILY` is granted only to an active
     * `PLAYER` who is not a child -- any other role, a deactivated player,
     * or a signed-in child is refused.
     */
    #[DataProvider('manageFamilyCases')]
    public function testManageFamilyTruthTable(UserRole $role, UserStatus $status, bool $isChild, bool $expectedGranted): void
    {
        [$voter, $repository] = $this->voterWithRepository();
        $user = new User('player@example.test', 'hash', $role, $status);

        $repository->method('findOneByChildUser')->willReturn(
            $isChild ? new ChildAccount($user, new User('parent@example.test', 'hash', UserRole::PLAYER)) : null,
        );

        $token = $this->tokenFor($user);

        $result = $voter->vote($token, null, [FamilyVoter::MANAGE_FAMILY]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, bool, bool}>
     */
    public static function manageFamilyCases(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $status) {
                foreach ([false, true] as $isChild) {
                    $granted = UserRole::PLAYER === $role && UserStatus::ACTIVE === $status && !$isChild;
                    yield \sprintf('%s / %s / child=%s', $role->value, $status->value, $isChild ? 'yes' : 'no') => [$role, $status, $isChild, $granted];
                }
            }
        }
    }

    /**
     * AC-7, AC-9, AC-18: `MANAGE_CHILD` is granted only when a
     * `ChildAccount(child, parent = token user)` exists and both accounts
     * are active -- a non-parent, a deactivated parent, or a deactivated
     * child is refused.
     */
    #[DataProvider('manageChildCases')]
    public function testManageChildTruthTable(bool $isRealParent, UserStatus $parentStatus, UserStatus $childStatus, bool $expectedGranted): void
    {
        [$voter, $repository] = $this->voterWithRepository();

        $parent = new User('parent@example.test', 'hash', UserRole::PLAYER, $parentStatus);
        $otherParent = new User('other-parent@example.test', 'hash', UserRole::PLAYER);
        $child = new User('child@example.test', 'hash', UserRole::PLAYER, $childStatus);

        $childAccount = new ChildAccount($child, $isRealParent ? $parent : $otherParent);
        $repository->method('findOneByChildUser')->willReturn($childAccount);

        $token = $this->tokenFor($parent);

        $result = $voter->vote($token, $child, [FamilyVoter::MANAGE_CHILD]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{bool, UserStatus, UserStatus, bool}>
     */
    public static function manageChildCases(): iterable
    {
        foreach ([true, false] as $isRealParent) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $parentStatus) {
                foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $childStatus) {
                    $granted = $isRealParent && UserStatus::ACTIVE === $parentStatus && UserStatus::ACTIVE === $childStatus;
                    yield \sprintf('realParent=%s / parent=%s / child=%s', $isRealParent ? 'yes' : 'no', $parentStatus->value, $childStatus->value) => [$isRealParent, $parentStatus, $childStatus, $granted];
                }
            }
        }
    }

    public function testManageChildAbstainsWhenSubjectIsNotAUser(): void
    {
        [$voter] = $this->voterWithRepository();
        $user = new User('player@example.test', 'hash', UserRole::PLAYER);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, new \stdClass(), [FamilyVoter::MANAGE_CHILD]),
        );
    }

    public function testManageFamilyAbstainsWhenASubjectIsGiven(): void
    {
        [$voter] = $this->voterWithRepository();
        $user = new User('player@example.test', 'hash', UserRole::PLAYER);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, new \stdClass(), [FamilyVoter::MANAGE_FAMILY]),
        );
    }

    public function testAbstainsOnAnEntirelyUnknownAttribute(): void
    {
        [$voter] = $this->voterWithRepository();
        $user = new User('player@example.test', 'hash', UserRole::PLAYER);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, null, ['SOME_OTHER_ATTRIBUTE']),
        );
    }

    public function testANonUserTokenSubjectIsDeniedRatherThanAbstainedForASupportedPair(): void
    {
        [$voter] = $this->voterWithRepository();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, null, [FamilyVoter::MANAGE_FAMILY]),
        );
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
