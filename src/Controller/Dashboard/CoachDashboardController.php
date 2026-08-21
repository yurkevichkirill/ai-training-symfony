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
 * Stub landing page for the Coach role. Task 37 gives it an accessible
 * shell; S1 only needs it to exist, to be reachable by its own role, and to be
 * refused to every other role.
 *
 * #[IsGranted] is the enforcement. The absence of a navigation link is not --
 * that is asserted in RoleLandingTest, which checks both together.
 *
 * S7 (Task 21, tier A): the coach's page chrome carries the branding of the
 * one trainer they are actively associated with, via
 * `TrainerBrandingResolver::forViewerChrome()` (S3's partial unique index
 * guarantees at most one).
 */
final class CoachDashboardController extends AbstractController
{
    #[Route('/coach', name: 'coach_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_COACH')]
    public function index(TrainerBrandingResolver $brandingResolver): Response
    {
        /** @var User $coach */
        $coach = $this->getUser();

        return $this->render('dashboard/coach.html.twig', [
            'branding' => $brandingResolver->forViewerChrome($coach),
        ]);
    }
}
