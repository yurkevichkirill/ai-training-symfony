<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ProfileTrainer;
use App\Entity\TrainerCoachAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ProfileRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Service\TrainerBrandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Task 33: `forViewerChrome()`'s tier-A table (D3) -- `null` for a player,
 * a parent, an admin, and an anonymous-shaped account, and the correct
 * trainer for a trainer and for a coach. Also asserts the tier-A
 * precondition explicitly: `findActiveForCoach()` is called at most once
 * and its result (at most one row, by S3's partial unique index) is what
 * determines the coach's single trainer -- guarding the Risk that a future
 * slice could change that database fact.
 */
final class TrainerBrandingResolverTest extends TestCase
{
    private function makeResolver(
        ?ProfileTrainer $profile = null,
        ?TrainerCoachAssociation $coachAssociation = null,
    ): TrainerBrandingResolver {
        $profileRepository = $this->createMock(ProfileRepository::class);
        $profileRepository->method('findTrainerProfile')->willReturn($profile);

        $trainerCoachAssociationRepository = $this->createMock(TrainerCoachAssociationRepository::class);
        $trainerCoachAssociationRepository->method('findActiveForCoach')->willReturn($coachAssociation);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/branding/logo/fake');

        return new TrainerBrandingResolver($profileRepository, $trainerCoachAssociationRepository, $urlGenerator);
    }

    /**
     * @return iterable<string, array{UserRole}>
     */
    public static function nonChromeRoles(): iterable
    {
        yield 'player' => [UserRole::PLAYER];
        yield 'super admin' => [UserRole::SUPER_ADMIN];
    }

    #[DataProvider('nonChromeRoles')]
    public function testForViewerChromeReturnsNullForRolesWithNoUnambiguousTrainer(UserRole $role): void
    {
        $viewer = new User('viewer@example.test', 'hash', $role, UserStatus::ACTIVE);
        $resolver = $this->makeResolver();

        self::assertNull($resolver->forViewerChrome($viewer));
    }

    public function testForViewerChromeReturnsTheTrainersOwnBrandingForATrainer(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $profile = new ProfileTrainer($trainer, 'Elite Academy');
        $profile->setPrimaryColorHex('#ff8800');

        $resolver = $this->makeResolver(profile: $profile);
        $branding = $resolver->forViewerChrome($trainer);

        self::assertNotNull($branding);
        self::assertSame('#ff8800', $branding->primaryColorHex);
    }

    public function testForViewerChromeReturnsNullForACoachWithNoActiveAssociation(): void
    {
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $resolver = $this->makeResolver(coachAssociation: null);

        self::assertNull($resolver->forViewerChrome($coach));
    }

    public function testForViewerChromeReturnsTheAssociatedTrainersBrandingForACoach(): void
    {
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $profile = new ProfileTrainer($trainer, 'Elite Academy');
        $profile->setPrimaryColorHex('#123456');

        $association = $this->createMock(TrainerCoachAssociation::class);
        $association->method('getTrainer')->willReturn($trainer);

        $profileRepository = $this->createMock(ProfileRepository::class);
        $profileRepository->method('findTrainerProfile')
            ->willReturnCallback(fn (User $u) => $u === $trainer ? $profile : null);

        $trainerCoachAssociationRepository = $this->createMock(TrainerCoachAssociationRepository::class);
        $trainerCoachAssociationRepository->expects(self::once())
            ->method('findActiveForCoach')
            ->with($coach)
            ->willReturn($association);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/branding/logo/fake');

        $resolver = new TrainerBrandingResolver($profileRepository, $trainerCoachAssociationRepository, $urlGenerator);

        $branding = $resolver->forViewerChrome($coach);

        self::assertNotNull($branding);
        self::assertSame('#123456', $branding->primaryColorHex);
    }

    /**
     * Guards the precondition this method's docblock names explicitly: a
     * coach's active-association lookup returns at most one row (S3's
     * `uniq_trainer_coach_active_coach` partial unique index), which is why
     * the resolver's coach branch does not need to disambiguate among
     * several rows.
     */
    public function testForViewerChromeCallsFindActiveForCoachExactlyOnceAndTrustsItsSingleRow(): void
    {
        $coach = new User('coach@example.test', 'hash', UserRole::COACH, UserStatus::ACTIVE);

        $trainerCoachAssociationRepository = $this->createMock(TrainerCoachAssociationRepository::class);
        $trainerCoachAssociationRepository->expects(self::once())
            ->method('findActiveForCoach')
            ->willReturn(null);

        $profileRepository = $this->createMock(ProfileRepository::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $resolver = new TrainerBrandingResolver($profileRepository, $trainerCoachAssociationRepository, $urlGenerator);

        self::assertNull($resolver->forViewerChrome($coach));
    }

    public function testForTrainerReturnsPlatformDefaultWhenBothColumnsAreNull(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);
        $profile = new ProfileTrainer($trainer, 'Elite Academy');

        $resolver = $this->makeResolver(profile: $profile);
        $branding = $resolver->forTrainer($trainer);

        self::assertSame('#0b5fae', $branding->primaryColorHex);
        self::assertFalse($branding->hasLogo());
    }

    public function testForTrainerReturnsPlatformDefaultWhenNoProfileExists(): void
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER, UserStatus::ACTIVE);

        $resolver = $this->makeResolver(profile: null);
        $branding = $resolver->forTrainer($trainer);

        self::assertSame('#0b5fae', $branding->primaryColorHex);
    }
}
