<?php

declare(strict_types=1);

namespace App\Controller\Family;

use App\Entity\User;
use App\Form\ChildTrainerAddFormType;
use App\Repository\UserRepository;
use App\Security\FamilyVoter;
use App\Service\ChildAccountResolver;
use App\Service\ChildTrainerService;
use App\Service\Exception\ChildNotOwnedByParentException;
use App\Service\Exception\NoActiveTrainerAssociationException;
use App\Service\Exception\ShareLinkUnavailableException;
use App\Service\PlayerShareLinkResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * An existing child's trainer connections: adding one more (by code or by
 * picking from the parent's own roster) and removing one, with a named
 * confirmation step first (Task 31, AC-8, AC-9, AC-10). Every action gates
 * on `FamilyVoter::MANAGE_CHILD` for the child named in the route.
 */
#[IsGranted('ROLE_PLAYER')]
final class ChildTrainerController extends AbstractController
{
    #[Route('/family/children/{id}/trainers/add', name: 'app_family_child_trainer_add', methods: ['GET', 'POST'])]
    public function add(
        Request $request,
        string $id,
        UserRepository $userRepository,
        ChildAccountResolver $childAccountResolver,
        PlayerShareLinkResolver $shareLinkResolver,
        ChildTrainerService $childTrainerService,
    ): Response {
        $childUser = $this->findChildUserOrFail($userRepository, $id);
        $this->denyAccessUnlessGranted(FamilyVoter::MANAGE_CHILD, $childUser);

        $child = $this->findChildAccountOrFail($childAccountResolver, $childUser);

        /** @var User $parent */
        $parent = $this->getUser();

        $form = $this->createForm(ChildTrainerAddFormType::class, null, ['parent' => $parent]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{shareLinkCode: ?string, trainerId: ?string} $data */
            $data = $form->getData();

            try {
                if (null !== $data['shareLinkCode'] && '' !== trim($data['shareLinkCode'])) {
                    $link = $shareLinkResolver->resolve($data['shareLinkCode']);
                    $trainer = $link->getTrainer();
                } else {
                    $link = null;
                    $trainer = $this->findTrainerOrFail($userRepository, (string) $data['trainerId']);
                }

                $childTrainerService->connect($parent, $child, $trainer, $link);

                $this->addFlash('success', 'Trainer connected.');

                return $this->redirectToRoute('app_family_index');
            } catch (ShareLinkUnavailableException $exception) {
                $form->get('shareLinkCode')->addError(new \Symfony\Component\Form\FormError($exception->getMessage()));
            }
        }

        return $this->render('family/child_trainer_add.html.twig', [
            'form' => $form,
            'childUser' => $childUser,
        ]);
    }

    #[Route('/family/children/{id}/trainers/{trainerId}/remove', name: 'app_family_child_trainer_remove_confirm', methods: ['GET'])]
    public function confirmRemove(
        string $id,
        string $trainerId,
        UserRepository $userRepository,
    ): Response {
        $childUser = $this->findChildUserOrFail($userRepository, $id);
        $this->denyAccessUnlessGranted(FamilyVoter::MANAGE_CHILD, $childUser);

        $trainer = $this->findTrainerOrFail($userRepository, $trainerId);

        return $this->render('family/child_trainer_remove_confirm.html.twig', [
            'childUser' => $childUser,
            'trainer' => $trainer,
        ]);
    }

    #[Route('/family/children/{id}/trainers/{trainerId}/remove', name: 'app_family_child_trainer_remove', methods: ['POST'])]
    public function remove(
        Request $request,
        string $id,
        string $trainerId,
        UserRepository $userRepository,
        ChildAccountResolver $childAccountResolver,
        ChildTrainerService $childTrainerService,
    ): Response {
        $childUser = $this->findChildUserOrFail($userRepository, $id);
        $this->denyAccessUnlessGranted(FamilyVoter::MANAGE_CHILD, $childUser);

        $child = $this->findChildAccountOrFail($childAccountResolver, $childUser);
        $trainer = $this->findTrainerOrFail($userRepository, $trainerId);

        if (!$this->isCsrfTokenValid('family_child_trainer_remove_'.$id.'_'.$trainerId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $parent */
        $parent = $this->getUser();

        try {
            $childTrainerService->disconnect($parent, $child, $trainer);
            $this->addFlash('success', 'Trainer removed.');
        } catch (ChildNotOwnedByParentException|NoActiveTrainerAssociationException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_family_index');
    }

    private function findChildAccountOrFail(ChildAccountResolver $childAccountResolver, User $childUser): \App\Entity\ChildAccount
    {
        $child = $childAccountResolver->childAccountOf($childUser);

        if (null === $child) {
            // Cannot happen once FamilyVoter::MANAGE_CHILD has been granted
            // for $childUser -- that vote is only ever true for an existing
            // ChildAccount row.
            throw $this->createNotFoundException();
        }

        return $child;
    }

    private function findChildUserOrFail(UserRepository $userRepository, string $id): User
    {
        $childUser = $userRepository->find($this->parseUuid($id));

        if (!$childUser instanceof User) {
            throw $this->createNotFoundException();
        }

        return $childUser;
    }

    private function findTrainerOrFail(UserRepository $userRepository, string $id): User
    {
        $trainer = $userRepository->find($this->parseUuid($id));

        if (!$trainer instanceof User) {
            throw $this->createNotFoundException();
        }

        return $trainer;
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
