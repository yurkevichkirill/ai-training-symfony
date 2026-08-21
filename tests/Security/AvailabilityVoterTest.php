<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ChildAccount;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ChildAccountRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Security\AvailabilityVoter;
use App\Service\ChildAccountResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The full eligibility truth table for `AvailabilityVoter` (Task 25,
 * AC-18, AC-20, AC-22, AC-23): `EDIT_AVAILABILITY` over self/parent/
 * unrelated-viewer combinations, and `VIEW_AVAILABILITY` adding the
 * connected-trainer case. Mirrors `ShareLinkVoterTest`'s data-provider-
 * parameterized shape: a mocked `ChildAccountRepository` and
 * `TrainerPlayerAssociationRepository` stand in for real collaborators.
 */
final class AvailabilityVoterTest extends TestCase
{
    /**
     * @return array{AvailabilityVoter, ChildAccountRepository&\PHPUnit\Framework\MockObject\Stub, TrainerPlayerAssociationRepository&\PHPUnit\Framework\MockObject\Stub}
     */
    private function voterWithDependencies(): array
    {
        $childAccountRepository = $this->createStub(ChildAccountRepository::class);
        $associationRepository = $this->createStub(TrainerPlayerAssociationRepository::class);
        $voter = new AvailabilityVoter(new ChildAccountResolver($childAccountRepository), $associationRepository);

        return [$voter, $childAccountRepository, $associationRepository];
    }

    /**
     * AC-18, AC-20: `EDIT_AVAILABILITY` is granted to the subject itself and
     * to the subject's real parent, and refused to an unrelated viewer
     * (including a different parent) and a trainer.
     */
    #[DataProvider('editAvailabilityCases')]
    public function testEditAvailabilityTruthTable(string $relationship, bool $expectedGranted): void
    {
        [$voter, $childAccountRepository] = $this->voterWithDependencies();

        $subject = new User('player@example.test', 'hash', UserRole::PLAYER);
        [$viewer, $childAccount] = $this->userFor($relationship, $subject);

        $childAccountRepository->method('findOneByChildUser')->willReturn($childAccount);

        $token = $this->tokenFor($viewer);

        $result = $voter->vote($token, $subject, [AvailabilityVoter::EDIT_AVAILABILITY]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function editAvailabilityCases(): iterable
    {
        yield 'self' => ['self', true];
        yield 'real parent' => ['real-parent', true];
        yield 'unrelated parent' => ['unrelated-parent', false];
        yield 'unrelated trainer' => ['unrelated-trainer', false];
        yield 'unrelated player' => ['unrelated-player', false];
    }

    /**
     * AC-18, AC-22, AC-23: `VIEW_AVAILABILITY` additionally grants a trainer
     * with an active association to the subject, and refuses one without.
     */
    #[DataProvider('viewAvailabilityTrainerCases')]
    public function testViewAvailabilityGrantsAConnectedTrainer(bool $hasActiveAssociation, bool $expectedGranted): void
    {
        [$voter, $childAccountRepository, $associationRepository] = $this->voterWithDependencies();

        $subject = new User('player@example.test', 'hash', UserRole::PLAYER);
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER);

        $childAccountRepository->method('findOneByChildUser')->willReturn(null);
        $associationRepository->method('findOneFor')->willReturn(
            $hasActiveAssociation ? new TrainerPlayerAssociation($trainer, $subject, null) : null,
        );

        $token = $this->tokenFor($trainer);

        $result = $voter->vote($token, $subject, [AvailabilityVoter::VIEW_AVAILABILITY]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function viewAvailabilityTrainerCases(): iterable
    {
        yield 'active association' => [true, true];
        yield 'no association' => [false, false];
    }

    /**
     * @return array{User, ?ChildAccount}
     */
    private function userFor(string $relationship, User $subject): array
    {
        return match ($relationship) {
            'self' => [$subject, null],
            'real-parent' => (function () use ($subject): array {
                $parent = new User('parent@example.test', 'hash', UserRole::PLAYER);

                return [$parent, new ChildAccount($subject, $parent)];
            })(),
            'unrelated-parent' => (function () use ($subject): array {
                $otherParent = new User('other-parent@example.test', 'hash', UserRole::PLAYER);
                $realParent = new User('real-parent@example.test', 'hash', UserRole::PLAYER);

                return [$otherParent, new ChildAccount($subject, $realParent)];
            })(),
            'unrelated-trainer' => [new User('trainer@example.test', 'hash', UserRole::TRAINER), null],
            'unrelated-player' => [new User('other-player@example.test', 'hash', UserRole::PLAYER), null],
            default => throw new \InvalidArgumentException($relationship),
        };
    }

    public function testAbstainsWhenSubjectIsNotAUser(): void
    {
        [$voter] = $this->voterWithDependencies();
        $user = new User('player@example.test', 'hash', UserRole::PLAYER);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, new \stdClass(), [AvailabilityVoter::EDIT_AVAILABILITY]),
        );
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, null, [AvailabilityVoter::VIEW_AVAILABILITY]),
        );
    }

    public function testAbstainsOnAnEntirelyUnknownAttribute(): void
    {
        [$voter] = $this->voterWithDependencies();
        $user = new User('player@example.test', 'hash', UserRole::PLAYER);
        $token = $this->tokenFor($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, $user, ['SOME_OTHER_ATTRIBUTE']),
        );
    }

    public function testANonUserTokenSubjectIsDeniedRatherThanAbstainedForASupportedPair(): void
    {
        [$voter] = $this->voterWithDependencies();
        $subject = new User('player@example.test', 'hash', UserRole::PLAYER);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $subject, [AvailabilityVoter::EDIT_AVAILABILITY]),
        );
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
