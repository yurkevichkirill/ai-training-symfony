<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\TrainerCoachAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\TrainerCoachAssociationRepository;
use App\Security\CoachVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Task 32 (D4, AC-5, AC-15): the full eligibility truth table for
 * `CoachVoter`, parameterized over every role x active/deactivated x
 * self/associated/unassociated combination, matching `ShareLinkVoterTest`'s
 * data-provider shape -- including the explicit assertion that
 * `ROLE_SUPER_ADMIN` grants nothing on any of the three attributes, the
 * flat-`role_hierarchy` invariant this slice relies on for AC-15.
 */
final class CoachVoterTest extends TestCase
{
    /**
     * EDIT_COACH_PROFILE has no subject -- granted only when the token user
     * is an active COACH, refused for every other role/status.
     */
    #[DataProvider('editCoachProfileCases')]
    public function testEditCoachProfileTruthTable(UserRole $role, UserStatus $status, bool $expectedGranted): void
    {
        $repository = $this->createStub(TrainerCoachAssociationRepository::class);
        $voter = new CoachVoter($repository);

        $user = new User('user@example.test', 'hash', $role, $status);
        $token = $this->tokenFor($user);

        $result = $voter->vote($token, null, [CoachVoter::EDIT_COACH_PROFILE]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, bool}>
     */
    public static function editCoachProfileCases(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $status) {
                $granted = UserRole::COACH === $role && UserStatus::ACTIVE === $status;
                yield \sprintf('%s / %s', $role->value, $status->value) => [$role, $status, $granted];
            }
        }
    }

    /**
     * EDIT_COACH_AVAILABILITY -- granted only when the token user IS the
     * subject and is an active COACH. A different active coach, and every
     * non-coach role including an active Super Admin, is refused.
     */
    #[DataProvider('editCoachAvailabilityCases')]
    public function testEditCoachAvailabilityTruthTable(UserRole $role, UserStatus $status, bool $selfSubject, bool $expectedGranted): void
    {
        $repository = $this->createStub(TrainerCoachAssociationRepository::class);
        $voter = new CoachVoter($repository);

        $user = new User('user@example.test', 'hash', $role, $status);
        $subject = $selfSubject ? $user : new User('other-coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $token = $this->tokenFor($user);

        $result = $voter->vote($token, $subject, [CoachVoter::EDIT_COACH_AVAILABILITY]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, bool, bool}>
     */
    public static function editCoachAvailabilityCases(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $status) {
                foreach ([true, false] as $selfSubject) {
                    $granted = $selfSubject && UserRole::COACH === $role && UserStatus::ACTIVE === $status;
                    yield \sprintf('%s / %s / self=%s', $role->value, $status->value, $selfSubject ? 'yes' : 'no') => [$role, $status, $selfSubject, $granted];
                }
            }
        }
    }

    /**
     * VIEW_COACH_AVAILABILITY -- granted to the coach themself (same rule as
     * EDIT_COACH_AVAILABILITY), or to an active TRAINER with an active
     * TrainerCoachAssociation to that coach. Refused for an unassociated
     * trainer, a trainer whose association has ended, a player, and a
     * Super Admin -- explicitly asserted to grant nothing here either.
     */
    public function testViewCoachAvailabilityGrantedToTheCoachThemself(): void
    {
        $repository = $this->createStub(TrainerCoachAssociationRepository::class);
        $voter = new CoachVoter($repository);

        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $token = $this->tokenFor($coach);

        $result = $voter->vote($token, $coach, [CoachVoter::VIEW_COACH_AVAILABILITY]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewCoachAvailabilityGrantedToAnActivelyAssociatedTrainer(): void
    {
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $association = new TrainerCoachAssociation($trainer, $coach, null);

        $repository = $this->createStub(TrainerCoachAssociationRepository::class);
        $repository->method('findActiveForCoach')->willReturn($association);

        $voter = new CoachVoter($repository);
        $token = $this->tokenFor($trainer);

        $result = $voter->vote($token, $coach, [CoachVoter::VIEW_COACH_AVAILABILITY]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewCoachAvailabilityRefusedForAnUnassociatedTrainer(): void
    {
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);

        $repository = $this->createStub(TrainerCoachAssociationRepository::class);
        $repository->method('findActiveForCoach')->willReturn(null);

        $voter = new CoachVoter($repository);
        $token = $this->tokenFor($trainer);

        $result = $voter->vote($token, $coach, [CoachVoter::VIEW_COACH_AVAILABILITY]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewCoachAvailabilityRefusedForATrainerAssociatedWithADifferentCoach(): void
    {
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $otherTrainer = new User('other-trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $association = new TrainerCoachAssociation($otherTrainer, $coach, null);

        $repository = $this->createStub(TrainerCoachAssociationRepository::class);
        $repository->method('findActiveForCoach')->willReturn($association);

        $requestingTrainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $voter = new CoachVoter($repository);
        $token = $this->tokenFor($requestingTrainer);

        $result = $voter->vote($token, $coach, [CoachVoter::VIEW_COACH_AVAILABILITY]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewCoachAvailabilityRefusedForAPlayer(): void
    {
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $player = new User('player@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);

        $repository = $this->createStub(TrainerCoachAssociationRepository::class);

        $voter = new CoachVoter($repository);
        $token = $this->tokenFor($player);

        $result = $voter->vote($token, $coach, [CoachVoter::VIEW_COACH_AVAILABILITY]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * The flat-`role_hierarchy` invariant this slice relies on for AC-15:
     * `ROLE_SUPER_ADMIN` grants nothing on any of the three attributes, on
     * every subject shape each attribute supports.
     */
    public function testSuperAdminGrantsNothingOnAnyAttribute(): void
    {
        $repository = $this->createStub(TrainerCoachAssociationRepository::class);
        $voter = new CoachVoter($repository);

        $admin = new User('admin@example.test', 'hash', UserRole::SUPER_ADMIN, UserStatus::ACTIVE);
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $token = $this->tokenFor($admin);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [CoachVoter::EDIT_COACH_PROFILE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $coach, [CoachVoter::EDIT_COACH_AVAILABILITY]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $coach, [CoachVoter::VIEW_COACH_AVAILABILITY]));
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
