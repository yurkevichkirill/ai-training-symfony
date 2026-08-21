<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ChildAccount;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ChildAccountRepository;
use App\Security\PlayerActionVoter;
use App\Service\ChildAccountResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The full eligibility truth table for `PlayerActionVoter` (Task 24,
 * AC-14): all four no-subject attributes -- identically granted only to an
 * active `PLAYER` who is not a child -- over every `UserRole` x active/
 * deactivated x child/adult combination. Mirrors `ShareLinkVoterTest`'s
 * data-provider-parameterized shape.
 */
final class PlayerActionVoterTest extends TestCase
{
    /**
     * @return iterable<string>
     */
    public static function attributes(): iterable
    {
        yield [PlayerActionVoter::MANAGE_OWN_TRAINER_CONNECTIONS];
        yield [PlayerActionVoter::DELETE_OWN_ACCOUNT];
        yield [PlayerActionVoter::MANAGE_PAYMENT_METHOD];
        yield [PlayerActionVoter::COMPLETE_PURCHASE];
    }

    /**
     * @return array{PlayerActionVoter, ChildAccountRepository&\PHPUnit\Framework\MockObject\Stub}
     */
    private function voterWithRepository(): array
    {
        $repository = $this->createStub(ChildAccountRepository::class);
        $voter = new PlayerActionVoter(new ChildAccountResolver($repository));

        return [$voter, $repository];
    }

    /**
     * AC-14: every one of the four attributes is granted only to an active
     * PLAYER who is not a child -- any other role, a deactivated player, or
     * a signed-in child is refused.
     */
    #[DataProvider('truthTableCases')]
    public function testAttributeTruthTable(string $attribute, UserRole $role, UserStatus $status, bool $isChild, bool $expectedGranted): void
    {
        [$voter, $repository] = $this->voterWithRepository();
        $user = new User('player@example.test', 'hash', $role, $status);

        $repository->method('findOneByChildUser')->willReturn(
            $isChild ? new ChildAccount($user, new User('parent@example.test', 'hash', UserRole::PLAYER)) : null,
        );

        $token = $this->tokenFor($user);

        $result = $voter->vote($token, null, [$attribute]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{string, UserRole, UserStatus, bool, bool}>
     */
    public static function truthTableCases(): iterable
    {
        $attributes = [
            PlayerActionVoter::MANAGE_OWN_TRAINER_CONNECTIONS,
            PlayerActionVoter::DELETE_OWN_ACCOUNT,
            PlayerActionVoter::MANAGE_PAYMENT_METHOD,
            PlayerActionVoter::COMPLETE_PURCHASE,
        ];

        foreach ($attributes as $attribute) {
            foreach (UserRole::cases() as $role) {
                foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $status) {
                    foreach ([false, true] as $isChild) {
                        $granted = UserRole::PLAYER === $role && UserStatus::ACTIVE === $status && !$isChild;
                        yield \sprintf('%s / %s / %s / child=%s', $attribute, $role->value, $status->value, $isChild ? 'yes' : 'no') => [$attribute, $role, $status, $isChild, $granted];
                    }
                }
            }
        }
    }

    #[DataProvider('attributes')]
    public function testAbstainsWhenASubjectIsGiven(string $attribute): void
    {
        [$voter] = $this->voterWithRepository();
        $user = new User('player@example.test', 'hash', UserRole::PLAYER);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, new \stdClass(), [$attribute]),
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

    public function testANonUserTokenSubjectIsDeniedRatherThanAbstainedForASupportedAttribute(): void
    {
        [$voter] = $this->voterWithRepository();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, null, [PlayerActionVoter::DELETE_OWN_ACCOUNT]),
        );
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
