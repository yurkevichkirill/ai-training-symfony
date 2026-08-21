<?php

declare(strict_types=1);

namespace App\Service;

use App\Branding\ContrastColor;
use App\Branding\TrainerBranding;
use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\ProfileRepository;
use App\Repository\TrainerCoachAssociationRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The three-tier branding-read rule (D3): given a trainer, or a viewer, or a
 * set of trainers, what branding renders. Read-only -- no writes, no
 * `flush()`, and **no caching of any kind** anywhere in this class; AC-11's
 * "no publish delay, no cache-clear, no re-login" is satisfied by reading
 * the row on the request that renders (NFR-001 is a single indexed lookup,
 * or one batched query for a set).
 */
final class TrainerBrandingResolver
{
    public function __construct(
        private readonly ProfileRepository $profileRepository,
        private readonly TrainerCoachAssociationRepository $trainerCoachAssociationRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * The one narrow question: given a trainer, what is their branding.
     * Both columns null yields the platform default (D1b).
     */
    public function forTrainer(User $trainer): TrainerBranding
    {
        $profile = $this->profileRepository->findTrainerProfile($trainer);

        if (!$profile instanceof ProfileTrainer) {
            return TrainerBranding::platformDefault();
        }

        return $this->fromProfile($profile);
    }

    /**
     * Tier A: page chrome, only where the viewer's own identity determines
     * exactly one trainer. A *total* function with no fallback: `TRAINER`
     * -> their own branding; `COACH` ->
     * `TrainerCoachAssociationRepository::findActiveForCoach()`'s trainer,
     * which is at most one row by S3's partial unique index
     * `uniq_trainer_coach_active_coach (coach_id) WHERE ended_at IS NULL`
     * (that database fact is this method's precondition -- a future slice
     * changing it would change this method's correctness); **every other
     * role, including `PLAYER`, returns `null`** (D3, D3b).
     */
    public function forViewerChrome(User $viewer): ?TrainerBranding
    {
        if (UserRole::TRAINER === $viewer->getRole()) {
            return $this->forTrainer($viewer);
        }

        if (UserRole::COACH === $viewer->getRole()) {
            $association = $this->trainerCoachAssociationRepository->findActiveForCoach($viewer);

            return null === $association ? null : $this->forTrainer($association->getTrainer());
        }

        return null;
    }

    /**
     * Tier B: batched, for any page rendering a *set* of trainers (a
     * roster, a family's connected trainers) -- one
     * `ProfileRepository::findTrainerProfilesFor()` query for the whole
     * page, never one per row (AC-11, NFR-001).
     *
     * @param list<User> $trainers
     *
     * @return array<string, TrainerBranding> keyed by trainer user id (RFC 4122 string)
     */
    public function forTrainers(array $trainers): array
    {
        $profilesByUserId = $this->profileRepository->findTrainerProfilesFor($trainers);

        $brandingByUserId = [];

        foreach ($trainers as $trainer) {
            $userId = $trainer->getId()->toRfc4122();
            $profile = $profilesByUserId[$userId] ?? null;

            $brandingByUserId[$userId] = $profile instanceof ProfileTrainer
                ? $this->fromProfile($profile)
                : TrainerBranding::platformDefault();
        }

        return $brandingByUserId;
    }

    private function fromProfile(ProfileTrainer $profile): TrainerBranding
    {
        $logoKey = $profile->getLogoKey();
        $primaryColorHex = $profile->getPrimaryColorHex();

        if (null === $logoKey && null === $primaryColorHex) {
            return TrainerBranding::platformDefault();
        }

        $primaryColorHex ??= '#0b5fae';

        return new TrainerBranding(
            logoUrl: null === $logoKey
                ? null
                : $this->urlGenerator->generate('app_branding_logo', ['trainerId' => $profile->getUser()->getId()->toRfc4122()]),
            primaryColorHex: $primaryColorHex,
            contrastColorHex: ContrastColor::forBackground($primaryColorHex),
        );
    }
}
