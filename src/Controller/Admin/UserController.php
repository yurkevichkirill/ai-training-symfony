<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Form\CreateTrainerFormType;
use App\Form\DeleteUserFormType;
use App\Form\ProfileCommonFormType;
use App\Repository\UserRepository;
use App\Service\AccountLifecycleService;
use App\Service\CreateTrainerRequest;
use App\Service\Exception\EmailAlreadyInUseException;
use App\Service\Exception\InvalidAccountStateTransitionException;
use App\Service\ProfileCommonRequest;
use App\Service\ProfileService;
use App\Service\TrainerOnboardingService;
use App\Service\UserSearchCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The Users tool (AC-1…AC-9, AC-13, AC-14, AC-17, AC-18): global directory,
 * trainer creation, admin-side profile edit, deactivate/reactivate/delete.
 * Reachable only by a Super Admin -- the class-level attribute is what makes
 * AC-8's "no route for self-registration of any other role" true, since
 * `TrainerOnboardingService` has no other caller.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/users')]
final class UserController extends AbstractController
{
    #[Route('', name: 'admin_users_index', methods: ['GET'])]
    public function index(Request $request, UserRepository $userRepository): Response
    {
        $roleParam = $request->query->get('role');
        $statusParam = $request->query->get('status');
        $afterCreatedAtParam = $request->query->get('after_created_at');

        $criteria = new UserSearchCriteria(
            role: null !== $roleParam ? UserRole::tryFrom((string) $roleParam) : null,
            status: null !== $statusParam ? UserStatus::tryFrom((string) $statusParam) : null,
            query: $request->query->get('q'),
            afterCreatedAt: null !== $afterCreatedAtParam ? new \DateTimeImmutable((string) $afterCreatedAtParam) : null,
            afterId: $request->query->get('after_id'),
        );

        $page = $userRepository->search($criteria);

        return $this->render('admin/user/index.html.twig', [
            'page' => $page,
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
            'criteria' => $criteria,
        ]);
    }

    #[Route('/create', name: 'admin_users_create', methods: ['GET', 'POST'])]
    public function create(Request $request, TrainerOnboardingService $trainerOnboardingService): Response
    {
        $form = $this->createForm(CreateTrainerFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, businessName: string, firstName: ?string, lastName: ?string, phone: ?string} $data */
            $data = $form->getData();
            $request = new CreateTrainerRequest(
                $data['email'],
                $data['businessName'],
                $data['firstName'] ?? null,
                $data['lastName'] ?? null,
                $data['phone'] ?? null,
            );

            /** @var User $actor */
            $actor = $this->getUser();

            try {
                $trainerOnboardingService->createTrainer($request, $actor);
            } catch (EmailAlreadyInUseException $exception) {
                $form->get('email')->addError(new \Symfony\Component\Form\FormError($exception->getMessage()));

                return $this->render('admin/user/create.html.twig', ['form' => $form]);
            }

            $this->addFlash('success', 'Trainer account created and invitation sent.');

            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/user/create.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'admin_users_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $id, UserRepository $userRepository, ProfileService $profileService): Response
    {
        $target = $this->findUserOrFail($userRepository, $id);

        if (UserStatus::DELETED === $target->getStatus()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ProfileCommonFormType::class, [
            'firstName' => $target->getFirstName(),
            'lastName' => $target->getLastName(),
            'phone' => $target->getPhone(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{firstName: ?string, lastName: ?string, phone: ?string} $data */
            $data = $form->getData();

            /** @var User $actor */
            $actor = $this->getUser();

            $profileService->updateCommon($target, new ProfileCommonRequest($data['firstName'] ?? null, $data['lastName'] ?? null, $data['phone'] ?? null), $actor);

            $this->addFlash('success', 'User updated.');

            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/user/edit.html.twig', ['form' => $form, 'target' => $target]);
    }

    #[Route('/{id}/deactivate', name: 'admin_users_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, string $id, UserRepository $userRepository, AccountLifecycleService $lifecycleService): Response
    {
        $target = $this->findUserOrFail($userRepository, $id);
        $this->assertCsrf($request, 'admin_deactivate_'.$id);

        /** @var User $actor */
        $actor = $this->getUser();

        try {
            $lifecycleService->deactivate($target, $actor);
            $this->addFlash('success', 'User deactivated.');
        } catch (InvalidAccountStateTransitionException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/reactivate', name: 'admin_users_reactivate', methods: ['POST'])]
    public function reactivate(Request $request, string $id, UserRepository $userRepository, AccountLifecycleService $lifecycleService): Response
    {
        $target = $this->findUserOrFail($userRepository, $id);
        $this->assertCsrf($request, 'admin_reactivate_'.$id);

        /** @var User $actor */
        $actor = $this->getUser();

        try {
            $lifecycleService->reactivate($target, $actor);
            $this->addFlash('success', 'User reactivated.');
        } catch (InvalidAccountStateTransitionException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/delete', name: 'admin_users_delete', methods: ['GET', 'POST'])]
    public function delete(Request $request, string $id, UserRepository $userRepository, AccountLifecycleService $lifecycleService): Response
    {
        $target = $this->findUserOrFail($userRepository, $id);
        $form = $this->createForm(DeleteUserFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $actor */
            $actor = $this->getUser();
            /** @var array{reason: ?string} $data */
            $data = $form->getData();

            try {
                $lifecycleService->delete($target, $actor, $data['reason'] ?? null);
                $this->addFlash('success', 'User deleted and PII anonymized.');
            } catch (InvalidAccountStateTransitionException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }

            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/user/delete.html.twig', ['form' => $form, 'target' => $target]);
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

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
