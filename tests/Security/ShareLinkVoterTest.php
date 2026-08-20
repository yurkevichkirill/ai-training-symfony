<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CoachInvitation;
use App\Entity\PlayerShareLink;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\ShareLinkVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The full eligibility truth table for `ShareLinkVoter` (Task 29, AC-20,
 * AC-21): all four `UserRole` cases x both attributes x active/inactive
 * status, plus (for the coach attribute) matching/mismatched email -- and
 * the unsupported-attribute/subject-type abstain cases `supports()` is
 * responsible for. Pure unit test: no kernel, a stubbed `TokenInterface`
 * stands in for a real security token.
 */
final class ShareLinkVoterTest extends TestCase
{
    private const INVITED_EMAIL = 'invited-coach@example.test';
    private const HASHED_VERIFIER = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcd';

    private ShareLinkVoter $voter;
    private PlayerShareLink $playerLink;
    private CoachInvitation $coachInvitation;

    protected function setUp(): void
    {
        $this->voter = new ShareLinkVoter();

        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER);
        $this->playerLink = new PlayerShareLink($trainer, 'abcdefghijkl');
        $this->coachInvitation = new CoachInvitation(
            $trainer,
            self::INVITED_EMAIL,
            'Casey Coach',
            null,
            'selector1234',
            self::HASHED_VERIFIER,
            new \DateTimeImmutable('+7 days'),
        );
    }

    /**
     * AC-20: `FOLLOW_PLAYER_SHARE_LINK` is granted to an active PLAYER and
     * refused to every other role/status combination -- a Coach, Trainer,
     * or Super Admin is refused regardless of status, and a DEACTIVATED
     * Player is refused too.
     */
    #[DataProvider('followPlayerShareLinkCases')]
    public function testFollowPlayerShareLinkTruthTableAc20(UserRole $role, UserStatus $status, bool $expectedGranted): void
    {
        $user = new User('visitor@example.test', 'hash', $role, $status);
        $token = $this->tokenFor($user);

        $result = $this->voter->vote($token, $this->playerLink, [ShareLinkVoter::FOLLOW_PLAYER_SHARE_LINK]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, bool}>
     */
    public static function followPlayerShareLinkCases(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $status) {
                $granted = UserRole::PLAYER === $role && UserStatus::ACTIVE === $status;
                yield \sprintf('%s / %s', $role->value, $status->value) => [$role, $status, $granted];
            }
        }
    }

    /**
     * AC-21: `ACCEPT_COACH_INVITATION` is granted only to an active COACH
     * whose own (already-normalized) email exactly matches the invitation's
     * `invitedEmail` -- every other role is refused regardless of status or
     * email, an inactive Coach is refused even with the right email, and an
     * active Coach with a different email is refused too.
     */
    #[DataProvider('acceptCoachInvitationCases')]
    public function testAcceptCoachInvitationTruthTableAc21(UserRole $role, UserStatus $status, bool $emailMatches, bool $expectedGranted): void
    {
        $email = $emailMatches ? self::INVITED_EMAIL : 'someone-else@example.test';
        $user = new User($email, 'hash', $role, $status);
        $token = $this->tokenFor($user);

        $result = $this->voter->vote($token, $this->coachInvitation, [ShareLinkVoter::ACCEPT_COACH_INVITATION]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, bool, bool}>
     */
    public static function acceptCoachInvitationCases(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $status) {
                foreach ([true, false] as $emailMatches) {
                    $granted = UserRole::COACH === $role && UserStatus::ACTIVE === $status && $emailMatches;
                    yield \sprintf('%s / %s / email %s', $role->value, $status->value, $emailMatches ? 'matches' : 'differs') => [$role, $status, $emailMatches, $granted];
                }
            }
        }
    }

    /**
     * `supports()` gates on subject type per attribute: neither attribute
     * recognizes the other's subject, so both pairings must abstain rather
     * than deny.
     */
    public function testAbstainsWhenTheAttributeAndSubjectTypeDoNotMatch(): void
    {
        $user = new User('coach@example.test', 'hash', UserRole::COACH);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, $this->coachInvitation, [ShareLinkVoter::FOLLOW_PLAYER_SHARE_LINK]),
        );
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, $this->playerLink, [ShareLinkVoter::ACCEPT_COACH_INVITATION]),
        );
    }

    public function testAbstainsOnAnEntirelyUnknownAttribute(): void
    {
        $user = new User('coach@example.test', 'hash', UserRole::COACH);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, $this->playerLink, ['SOME_OTHER_ATTRIBUTE']),
        );
    }

    /**
     * A token whose subject is not an `App\Entity\User` at all (the
     * anonymous-visitor case the class docblock describes) is denied, not
     * abstained: `supports()` already matches the attribute/subject pair,
     * so `voteOnAttribute()`'s own `!$user instanceof User` guard is what
     * decides this, and that guard returns false, not null.
     */
    public function testANonUserTokenSubjectIsDeniedRatherThanAbstainedForASupportedPair(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $this->playerLink, [ShareLinkVoter::FOLLOW_PLAYER_SHARE_LINK]),
        );
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
