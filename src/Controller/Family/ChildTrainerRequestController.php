<?php

declare(strict_types=1);

namespace App\Controller\Family;

use App\Entity\ChildTrainerRequest;
use App\Entity\User;
use App\Repository\ChildTrainerRequestRepository;
use App\Service\ChildTrainerService;
use App\Service\Exception\ChildNotOwnedByParentException;
use App\Service\Exception\ChildTrainerRequestAlreadyResolvedException;
use App\Service\Exception\ShareLinkUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * A parent's review of a blocked ShareLink click their child made
 * (Task 32, AC-16, AC-17): approving connects the child to that trainer
 * exactly as `ChildTrainerController::add()` would; dismissing makes no
 * connection at all. Every action re-checks the request names this parent
 * -- `findPendingForParent()` is what the review list is built from, but
 * every mutating action here still asserts ownership itself rather than
 * trusting a route-supplied id alone.
 */
#[IsGranted('ROLE_PLAYER')]
final class ChildTrainerRequestController extends AbstractController
{
    #[Route('/family/requests/{id}', name: 'app_family_request_review', methods: ['GET'])]
    public function review(string $id, ChildTrainerRequestRepository $requestRepository): Response
    {
        $request = $this->findOwnedRequestOrFail($requestRepository, $id);

        return $this->render('family/request_review.html.twig', ['requestEntity' => $request]);
    }

    #[Route('/family/requests/{id}/approve', name: 'app_family_request_approve', methods: ['POST'])]
    public function approve(Request $httpRequest, string $id, ChildTrainerRequestRepository $requestRepository, ChildTrainerService $childTrainerService): Response
    {
        $request = $this->findOwnedRequestOrFail($requestRepository, $id);

        if (!$this->isCsrfTokenValid('family_request_approve_'.$id, (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $parent */
        $parent = $this->getUser();

        try {
            $childTrainerService->approveRequest($parent, $request);
            $this->addFlash('success', 'Trainer connection approved.');
        } catch (ChildTrainerRequestAlreadyResolvedException|ChildNotOwnedByParentException|ShareLinkUnavailableException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_family_index');
    }

    #[Route('/family/requests/{id}/dismiss', name: 'app_family_request_dismiss', methods: ['POST'])]
    public function dismiss(Request $httpRequest, string $id, ChildTrainerRequestRepository $requestRepository, ChildTrainerService $childTrainerService): Response
    {
        $request = $this->findOwnedRequestOrFail($requestRepository, $id);

        if (!$this->isCsrfTokenValid('family_request_dismiss_'.$id, (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $parent */
        $parent = $this->getUser();

        try {
            $childTrainerService->dismissRequest($parent, $request);
            $this->addFlash('success', 'Request dismissed.');
        } catch (ChildTrainerRequestAlreadyResolvedException|ChildNotOwnedByParentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_family_index');
    }

    /**
     * `ChildTrainerService`'s own guards re-check request/parent ownership
     * on every mutating call (defence-in-depth, S3 Decision Q4); this is
     * the read-path/edge equivalent, so a parent cannot even view another
     * parent's pending request by guessing its id.
     */
    private function findOwnedRequestOrFail(ChildTrainerRequestRepository $requestRepository, string $id): ChildTrainerRequest
    {
        $request = $requestRepository->findByIdWithAssociations($this->parseUuid($id));

        if (!$request instanceof ChildTrainerRequest) {
            throw $this->createNotFoundException();
        }

        /** @var User $parent */
        $parent = $this->getUser();

        if (!$request->getParentUser()->getId()->equals($parent->getId())) {
            throw $this->createAccessDeniedException();
        }

        return $request;
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
