<?php

declare(strict_types=1);

namespace App\Controller\Trainer;

use App\Entity\User;
use App\Service\PlayerShareLinkService;
use App\Service\TrainerBrandingResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A trainer's own player ShareLink (AC-4): idempotent get-or-create, so
 * repeat visits never mint a second link. `#[IsGranted]` at the class level
 * is S1's belt-and-braces rule every `Trainer\*Controller` follows.
 */
#[IsGranted('ROLE_TRAINER')]
final class ShareLinkController extends AbstractController
{
    #[Route('/trainer/share-link', name: 'app_trainer_share_link', methods: ['GET'])]
    public function show(PlayerShareLinkService $shareLinkService, TrainerBrandingResolver $brandingResolver): Response
    {
        /** @var User $trainer */
        $trainer = $this->getUser();

        $link = $shareLinkService->getOrCreateFor($trainer);

        return $this->render('trainer/share_link/show.html.twig', [
            'link' => $link,
            'branding' => $brandingResolver->forViewerChrome($trainer),
        ]);
    }
}
