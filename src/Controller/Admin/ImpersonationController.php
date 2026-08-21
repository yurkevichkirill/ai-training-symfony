<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\ImpersonationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * S6 (AC-1, AC-2, AC-3): the confirmation page the Users directory's
 * "Impersonate" row action links to. `denyAccessUnlessGranted` is called
 * with `ROLE_ALLOWED_TO_SWITCH` -- the exact attribute `SwitchUserListener`
 * itself will consult -- so a Super Admin target, a deactivated target, or
 * the admin's own row is refused before the page ever renders, and cannot
 * drift from the listener's own decision (D5).
 *
 * **No start action lives here, and no session write happens here.** The
 * POST from the confirmation form is intercepted by `SwitchUserListener`
 * at `kernel.request` priority 8, before routing -- so this action's own
 * POST branch is reached *only* when `_switch_user` is absent or mangled
 * client-side, in which case it re-renders the form with an error rather
 * than 404ing or crashing. The audit row is written from
 * `ImpersonationSwitchSubscriber` on `SecurityEvents::SWITCH_USER` (D2c),
 * the only place both the actor and the subject are known with certainty.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ImpersonationController extends AbstractController
{
    #[Route('/admin/users/{id}/impersonate', name: 'admin_impersonation_confirm', methods: ['GET', 'POST'])]
    public function confirm(Request $request, string $id, UserRepository $userRepository): Response
    {
        $target = $this->findUserOrFail($userRepository, $id);

        $this->denyAccessUnlessGranted(ImpersonationVoter::ROLE_ALLOWED_TO_SWITCH, $target);

        if ($request->isMethod('POST')) {
            // Reached only when the native listener did not intercept the
            // POST first (i.e. `_switch_user` was missing or malformed) --
            // an honest re-render with an error, not dead code.
            $this->addFlash('error', 'Unable to start impersonation. Please try again.');
        }

        return $this->render('admin/impersonation/confirm.html.twig', ['target' => $target]);
    }

    private function findUserOrFail(UserRepository $userRepository, string $id): User
    {
        $user = $userRepository->find($this->parseUuid($id));

        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    private function parseUuid(string $id): Uuid
    {
        try {
            return Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
    }
}
