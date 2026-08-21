<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ChildAccount;
use App\Entity\TrainerCoachAssociation;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ChildAccountRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Security\BrandingVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Task 34: the full truth table for `BrandingVoter::EDIT_BRANDING`/
 * `VIEW_BRANDING` -- role x active/deactivated x
 * self/associated/parent-of-associated/unassociated, including the explicit
 * assertion that `ROLE_SUPER_ADMIN` grants `EDIT_BRANDING` only through its
 * own clause under the flat `role_hierarchy` (matching
 * `ImpersonationVoterTest`'s shape). (AC-2, BR-001, D5)
 */
final class BrandingVoterTest extends TestCase
{
    private function makeVoter(
        ?TrainerPlayerAssociation $playerAssociation = null,
        ?TrainerCoachAssociation $coachAssociation = null,
        array $childAccounts = [],
    ): BrandingVoter {
        $trainerPlayerAssociationRepository = $this->createMock(TrainerPlayerAssociationRepository::class);
        $trainerPlayerAssociationRepository->method('findOneFor')->willReturn($playerAssociation);

        $trainerCoachAssociationRepository = $this->createMock(TrainerCoachAssociationRepository::class);
        $trainerCoachAssociationRepository->method('findActiveForCoach')->willReturn($coachAssociation);

        $childAccountRepository = $this->createMock(ChildAccountRepository::class);
        $childAccountRepository->method('findChildrenOf')->willReturn($childAccounts);

        return new BrandingVoter($trainerPlayerAssociationRepository, $trainerCoachAssociationRepository, $childAccountRepository);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, bool}>
     */
    public static function editBrandingSelfCases(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach ([UserStatus::ACTIVE, UserStatus::DEACTIVATED] as $status) {
                // Only an active TRAINER, editing themselves, is granted --
                // deactivated trainer is refused regardless of role.
                $granted = UserRole::TRAINER === $role && UserStatus::ACTIVE === $status;
                yield \sprintf('self, %s/%s', $role->value, $status->value) => [$role, $status, $granted];
            }
        }
    }

    #[DataProvider('editBrandingSelfCases')]
    public function testEditBrandingSelfTruthTable(UserRole $role, UserStatus $status, bool $expectedGranted): void
    {
        $subject = new User('trainer@example.test', 'hash', $role, $status);
        $voter = $this->makeVoter();

        $result = $voter->vote($this->tokenFor($subject), $subject, [BrandingVoter::EDIT_BRANDING]);

        self::assertSame(
            $expectedGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result,
        );
    }

    /**
     * The flat role_hierarchy fact: a SUPER_ADMIN grants EDIT_BRANDING only
     * through its own explicit clause, never inherited, and only for an
     * active trainer target.
     */
    public function testAnActiveSuperAdminCanEditAnyActiveTrainersBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $admin = new User('admin@example.test', 'hash', UserRole::SUPER_ADMIN, UserStatus::ACTIVE);
        $voter = $this->makeVoter();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->tokenFor($admin), $trainer, [BrandingVoter::EDIT_BRANDING]),
        );
    }

    public function testADeactivatedSuperAdminCannotEditAnyTrainersBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $admin = new User('admin@example.test', 'hash', UserRole::SUPER_ADMIN, UserStatus::DEACTIVATED);
        $voter = $this->makeVoter();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($admin), $trainer, [BrandingVoter::EDIT_BRANDING]),
        );
    }

    public function testASuperAdminCannotEditADeactivatedTrainersBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::DEACTIVATED);
        $admin = new User('admin@example.test', 'hash', UserRole::SUPER_ADMIN, UserStatus::ACTIVE);
        $voter = $this->makeVoter();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($admin), $trainer, [BrandingVoter::EDIT_BRANDING]),
        );
    }

    public function testAnUnrelatedTrainerCannotEditAnotherTrainersBranding(): void
    {
        $trainer = new User('trainer-a@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $otherTrainer = new User('trainer-b@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $voter = $this->makeVoter();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($otherTrainer), $trainer, [BrandingVoter::EDIT_BRANDING]),
        );
    }

    public function testAnAssociatedPlayerCannotEditBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $player = new User('player@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);
        $association = $this->createMock(TrainerPlayerAssociation::class);
        $voter = $this->makeVoter(playerAssociation: $association);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($player), $trainer, [BrandingVoter::EDIT_BRANDING]),
        );
    }

    public function testTheTrainerThemselfCanViewTheirOwnBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $voter = $this->makeVoter();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->tokenFor($trainer), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAnActiveSuperAdminCanViewAnyActiveTrainersBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $admin = new User('admin@example.test', 'hash', UserRole::SUPER_ADMIN, UserStatus::ACTIVE);
        $voter = $this->makeVoter();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->tokenFor($admin), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAnAssociatedPlayerCanViewBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $player = new User('player@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);
        $association = $this->createMock(TrainerPlayerAssociation::class);
        $voter = $this->makeVoter(playerAssociation: $association);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->tokenFor($player), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAnUnassociatedPlayerCannotViewBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $player = new User('player@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);
        $voter = $this->makeVoter(playerAssociation: null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($player), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAnAssociatedCoachCanViewBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);

        $association = $this->createMock(TrainerCoachAssociation::class);
        $association->method('getTrainer')->willReturn($trainer);

        $voter = $this->makeVoter(coachAssociation: $association);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->tokenFor($coach), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testACoachAssociatedWithADifferentTrainerCannotViewThisTrainersBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $otherTrainer = new User('trainer-b@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);

        $association = $this->createMock(TrainerCoachAssociation::class);
        $association->method('getTrainer')->willReturn($otherTrainer);

        $voter = $this->makeVoter(coachAssociation: $association);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($coach), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAParentOfAnAssociatedChildCanViewBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $parent = new User('parent@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);
        $childUser = new User('child@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);

        $childAccount = $this->createMock(ChildAccount::class);
        $childAccount->method('getChildUser')->willReturn($childUser);

        $association = $this->createMock(TrainerPlayerAssociation::class);

        $trainerPlayerAssociationRepository = $this->createMock(TrainerPlayerAssociationRepository::class);
        $trainerPlayerAssociationRepository->method('findOneFor')
            ->willReturnCallback(fn (User $t, User $player) => $player === $childUser ? $association : null);

        $trainerCoachAssociationRepository = $this->createMock(TrainerCoachAssociationRepository::class);
        $childAccountRepository = $this->createMock(ChildAccountRepository::class);
        $childAccountRepository->method('findChildrenOf')->willReturn([$childAccount]);

        $voter = new BrandingVoter($trainerPlayerAssociationRepository, $trainerCoachAssociationRepository, $childAccountRepository);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->tokenFor($parent), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAParentOfAnUnassociatedChildCannotViewBranding(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $parent = new User('parent@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);
        $childUser = new User('child@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);

        $childAccount = $this->createMock(ChildAccount::class);
        $childAccount->method('getChildUser')->willReturn($childUser);

        $voter = $this->makeVoter(playerAssociation: null, childAccounts: [$childAccount]);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($parent), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAnUnassociatedTrainerCannotViewAnotherTrainersBranding(): void
    {
        $trainer = new User('trainer-a@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $otherTrainer = new User('trainer-b@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $voter = $this->makeVoter();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($otherTrainer), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testViewBrandingRefusesADeactivatedTrainerSubjectEvenForAnAssociatedPlayer(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::DEACTIVATED);
        $player = new User('player@example.test', 'hash', UserRole::PLAYER, UserStatus::ACTIVE);
        $association = $this->createMock(TrainerPlayerAssociation::class);
        $voter = $this->makeVoter(playerAssociation: $association);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($player), $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAnAnonymousTokenIsRefused(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $voter = $this->makeVoter();

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $trainer, [BrandingVoter::VIEW_BRANDING]),
        );
    }

    public function testAnUnsupportedSubjectAbstains(): void
    {
        $voter = $this->makeVoter();
        $user = new User('user@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->tokenFor($user), new \stdClass(), [BrandingVoter::VIEW_BRANDING]),
        );
    }
}
