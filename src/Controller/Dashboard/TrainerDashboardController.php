<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Service\TrainerBrandingResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Stub landing page for the Trainer role. Task 37 gives it an accessible
 * shell; S1 only needs it to exist, to be reachable by its own role, and to be
 * refused to every other role.
 *
 * #[IsGranted] is the enforcement. The absence of a navigation link is not --
 * that is asserted in RoleLandingTest, which checks both together.
 *
 * S7 (Task 20, tier A): the trainer's own page chrome, via
 * `TrainerBrandingResolver::forViewerChrome()`.
 */
final class TrainerDashboardController extends AbstractController
{
    #[Route('/trainer', name: 'trainer_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_TRAINER')]
    public function index(TrainerBrandingResolver $brandingResolver): Response
    {
        /** @var User $trainer */
        $trainer = $this->getUser();

        return $this->render('dashboard/trainer.html.twig', [
            'branding' => $brandingResolver->forViewerChrome($trainer),
        ]);
    }
}
