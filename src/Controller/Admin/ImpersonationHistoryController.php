<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\ImpersonationVoter;
use App\Service\ImpersonationSearchCriteria;
use App\Service\ImpersonationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * S6 (AC-12, AC-13, AC-14, D6): the "Impersonation History" report. Kept
 * on its own controller rather than folded into `Admin\UserController`
 * because it is a cross-cutting, read-only report over a different
 * entity with its own criteria/pagination shape, not an action keyed on
 * one `{id}` user (D6).
 *
 * Read-only: no POST, no form action beyond the GET filter, no write of
 * any kind. Class-level `#[IsGranted('ROLE_SUPER_ADMIN')]` plus the
 * action-level `VIEW_IMPERSONATION_HISTORY` check (AC-12) -- the voter
 * attribute is checked in addition to the class guard, per the same
 * belt-and-suspenders pattern the confirmation controller uses for the
 * target.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ImpersonationHistoryController extends AbstractController
{
    #[Route('/admin/impersonation-history', name: 'admin_impersonation_history', methods: ['GET'])]
    public function index(Request $request, ImpersonationService $impersonationService): Response
    {
        $this->denyAccessUnlessGranted(ImpersonationVoter::VIEW_IMPERSONATION_HISTORY);

        $actorId = $this->parseUuid($request->query->get('actor_id'));
        $subjectId = $this->parseUuid($request->query->get('subject_id'));
        $startedFrom = $this->parseDate($request->query->get('started_from'));
        $startedUntil = $this->parseDate($request->query->get('started_until'));
        $afterStartedAt = $this->parseDate($request->query->get('after_started_at'));
        $afterIdParam = $request->query->get('after_id');
        $afterId = null !== $afterIdParam ? $this->parseUuid((string) $afterIdParam) : null;

        $criteria = new ImpersonationSearchCriteria(
            actorId: $actorId,
            subjectId: $subjectId,
            startedFrom: $startedFrom,
            startedUntil: $startedUntil,
            afterStartedAt: $afterStartedAt,
            afterId: $afterId,
        );

        $page = $impersonationService->search($criteria);

        return $this->render('admin/impersonation/history.html.twig', [
            'page' => $page,
            'criteria' => $criteria,
        ]);
    }

    private function parseUuid(mixed $value): ?Uuid
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return Uuid::fromString((string) $value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return null;
        }
    }
}
