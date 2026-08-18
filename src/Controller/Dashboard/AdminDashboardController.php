<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Stub landing page for the Super Admin role. Task 37 gives it an accessible
 * shell; S1 only needs it to exist, to be reachable by its own role, and to be
 * refused to every other role.
 *
 * #[IsGranted] is the enforcement. The absence of a navigation link is not --
 * that is asserted in RoleLandingTest, which checks both together.
 */
final class AdminDashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function index(): Response
    {
        return $this->render('dashboard/admin.html.twig');
    }
}
