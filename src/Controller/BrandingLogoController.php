<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Repository\ProfileRepository;
use App\Repository\UserRepository;
use App\Security\BrandingVoter;
use App\Service\Exception\ShareLinkUnavailableException;
use App\Service\FileStorage;
use App\Service\PlayerShareLinkResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Serves a trainer's branding logo through an authorized, per-request
 * check (AC-6, AC-7, NFR-002) -- the opaque `FileStorage` key is never a
 * directly browsable, guessable static file path, even though a logo is
 * intentionally visible to an entire organization rather than to one user
 * (`PhotoController`'s discipline, applied to a broader audience via
 * `BrandingVoter::VIEW_BRANDING`).
 *
 * `show()` sits under the existing `^/` authenticated catch-all -- no
 * `security.yaml` edit needed. `showForShareLink()` is the one exception:
 * authorization there is possession of the ShareLink code, resolved
 * exactly as `/join/{code}` itself resolves it, under the already-public
 * `^/join` allow-list -- no voter over an anonymous token.
 */
final class BrandingLogoController extends AbstractController
{
    #[Route('/branding/logo/{trainerId}', name: 'app_branding_logo', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(
        string $trainerId,
        UserRepository $userRepository,
        ProfileRepository $profileRepository,
        FileStorage $fileStorage,
    ): Response {
        // Task 36 (security review): a `{trainerId}` that is not a UUID at
        // all is an unknown logo, not a server error -- `Uuid::fromString()`
        // throws, and left uncaught that is a 500 (plus a dev stack trace)
        // any authenticated user can provoke with one request. Same
        // try/catch shape as `Player\TrainerRosterController`.
        try {
            $trainerUuid = Uuid::fromString($trainerId);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $trainer = $userRepository->find($trainerUuid);

        // 404 when the id resolves to no user at all. A user who exists but
        // is not an *associated, active trainer* gets 403 from the voter
        // below -- the architecture's stated behavior for the unassociated
        // reader (AC-6, AC-7).
        if (!$trainer instanceof User) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(BrandingVoter::VIEW_BRANDING, $trainer);

        return $this->serveLogo($trainer, $profileRepository, $fileStorage);
    }

    #[Route('/join/{code}/logo', name: 'app_share_link_logo', methods: ['GET'])]
    public function showForShareLink(
        string $code,
        PlayerShareLinkResolver $shareLinkResolver,
        ProfileRepository $profileRepository,
        FileStorage $fileStorage,
    ): Response {
        try {
            $link = $shareLinkResolver->resolve($code);
        } catch (ShareLinkUnavailableException) {
            throw $this->createNotFoundException();
        }

        return $this->serveLogo($link->getTrainer(), $profileRepository, $fileStorage);
    }

    private function serveLogo(User $trainer, ProfileRepository $profileRepository, FileStorage $fileStorage): Response
    {
        $profile = $profileRepository->findTrainerProfile($trainer);

        if (!$profile instanceof ProfileTrainer) {
            throw $this->createNotFoundException();
        }

        $logoKey = $profile->getLogoKey();

        if (null === $logoKey) {
            throw $this->createNotFoundException('This trainer has no logo.');
        }

        return $fileStorage->read($logoKey);
    }
}
