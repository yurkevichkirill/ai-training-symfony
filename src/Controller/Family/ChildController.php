<?php

declare(strict_types=1);

namespace App\Controller\Family;

use App\Entity\ChildAccount;
use App\Entity\User;
use App\Enum\PlayerGender;
use App\Form\ChildProfileFormType;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Repository\UserRepository;
use App\Security\FamilyVoter;
use App\Service\ChildAccountResolver;
use App\Service\ChildAccountService;
use App\Service\CreateChildRequest;
use App\Service\Exception\EmailAlreadyInUseException;
use App\Service\Exception\FileTooLargeException;
use App\Service\Exception\UnsupportedFileTypeException;
use App\Service\ProfileService;
use App\Service\TrainerBrandingResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

/**
 * The family list, child creation, per-child photo upload, and sign-in
 * enablement (Task 30, AC-1...AC-7). `index()`/`create()` gate on
 * `FamilyVoter::MANAGE_FAMILY`; every per-child action additionally gates on
 * `FamilyVoter::MANAGE_CHILD` for that specific child, so one parent can
 * never reach another parent's child through a guessed id.
 */
#[IsGranted('ROLE_PLAYER')]
final class ChildController extends AbstractController
{
    #[Route('/family', name: 'app_family_index', methods: ['GET'])]
    public function index(
        ChildAccountService $childAccountService,
        TrainerPlayerAssociationRepository $associationRepository,
        TrainerBrandingResolver $brandingResolver,
    ): Response {
        $this->denyAccessUnlessGranted(FamilyVoter::MANAGE_FAMILY);

        /** @var User $parent */
        $parent = $this->getUser();

        $children = $childAccountService->findChildrenOf($parent);
        $childUsers = array_map(static fn (ChildAccount $child): User => $child->getChildUser(), $children);

        $trainersByPlayer = [];
        $trainers = [];
        foreach ($associationRepository->findActiveForPlayers($childUsers) as $association) {
            $trainersByPlayer[$association->getPlayer()->getId()->toRfc4122()][] = $association;
            $trainers[$association->getTrainer()->getId()->toRfc4122()] = $association->getTrainer();
        }

        // S7 (Task 23, tier B): one batched call across every trainer any
        // child is connected to, no N+1 -- this cross-child view otherwise
        // renders no `branding` chrome variable at all (D3b).
        return $this->render('family/index.html.twig', [
            'children' => $children,
            'trainersByPlayer' => $trainersByPlayer,
            'brandingByTrainer' => $brandingResolver->forTrainers(array_values($trainers)),
        ]);
    }

    #[Route('/family/children/new', name: 'app_family_child_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        ChildAccountService $childAccountService,
        TrainerPlayerAssociationRepository $associationRepository,
    ): Response {
        $this->denyAccessUnlessGranted(FamilyVoter::MANAGE_FAMILY);

        /** @var User $parent */
        $parent = $this->getUser();

        $form = $this->createForm(ChildProfileFormType::class, null, ['parent' => $parent]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{
             *     childName: string,
             *     age: int,
             *     gender: PlayerGender,
             *     school: ?string,
             *     photo: ?UploadedFile,
             *     trainerIds: bool|list<string>|null,
             * } $data
             */
            $data = $form->getData();

            $childAccountService->createChild($parent, new CreateChildRequest(
                childName: $data['childName'],
                age: $data['age'],
                gender: $data['gender'],
                school: $data['school'] ?? null,
                photo: $data['photo'] ?? null,
                trainerIds: $this->normalizeTrainerIds($data['trainerIds'] ?? null, $parent, $associationRepository),
            ));

            $this->addFlash('success', 'Child profile created.');

            return $this->redirectToRoute('app_family_index');
        }

        return $this->render('family/child_new.html.twig', ['form' => $form]);
    }

    #[Route('/family/children/{id}/photo', name: 'app_family_child_photo', methods: ['POST'])]
    public function uploadPhoto(
        Request $request,
        string $id,
        UserRepository $userRepository,
        ProfileService $profileService,
    ): Response {
        $childUser = $this->findChildUserOrFail($userRepository, $id);
        $this->denyAccessUnlessGranted(FamilyVoter::MANAGE_CHILD, $childUser);

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $file = $request->files->get('photo');

        if ($file instanceof UploadedFile) {
            try {
                $profileService->uploadPhoto($childUser, $file);
                $this->addFlash('success', "Child's photo updated.");
            } catch (FileTooLargeException|UnsupportedFileTypeException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_family_index');
    }

    #[Route('/family/children/{id}/sign-in', name: 'app_family_child_sign_in', methods: ['GET', 'POST'])]
    public function enableSignIn(
        Request $request,
        string $id,
        UserRepository $userRepository,
        ChildAccountResolver $childAccountResolver,
        ChildAccountService $childAccountService,
    ): Response {
        $childUser = $this->findChildUserOrFail($userRepository, $id);
        $this->denyAccessUnlessGranted(FamilyVoter::MANAGE_CHILD, $childUser);

        $child = $childAccountResolver->childAccountOf($childUser);

        if (null === $child) {
            // Cannot happen once the voter above has granted MANAGE_CHILD --
            // that check itself is only ever true for an existing
            // ChildAccount row (FamilyVoter::isParentOf()). Guards against a
            // silent null-pointer if that invariant is ever broken.
            throw $this->createNotFoundException();
        }

        /** @var User $parent */
        $parent = $this->getUser();

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('family_child_sign_in_'.$id, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = trim((string) $request->request->get('email'));
            $violations = Validation::createValidator()->validate($email, [new NotBlank(), new Email()]);

            if (0 === \count($violations)) {
                try {
                    $childAccountService->enableSignIn($parent, $child, User::normalizeEmail($email), null);

                    $this->addFlash('success', 'Sign-in enabled -- an invitation email has been sent.');

                    return $this->redirectToRoute('app_family_index');
                } catch (EmailAlreadyInUseException $exception) {
                    $error = $exception->getMessage();
                }
            } else {
                $error = 'Enter a valid email address.';
            }
        }

        return $this->render('family/child_sign_in.html.twig', [
            'child' => $child,
            'error' => $error,
        ]);
    }

    /**
     * `ChildProfileFormType::addTrainerField()`'s single-trainer shape
     * submits a plain bool (Yes/No), with no id in the payload at all --
     * there is only ever one possible active association to mean, so this
     * re-derives it the same way the form itself built the checkbox's label
     * (`TrainerPlayerAssociationRepository::findActiveForPlayer($parent)`),
     * rather than inventing a hidden id field a tampered request could
     * override.
     *
     * @param bool|list<string>|null $trainerIds
     *
     * @return list<string>
     */
    private function normalizeTrainerIds(bool|array|null $trainerIds, User $parent, TrainerPlayerAssociationRepository $associationRepository): array
    {
        if (\is_array($trainerIds)) {
            return array_values($trainerIds);
        }

        if (true === $trainerIds) {
            $associations = $associationRepository->findActiveForPlayer($parent);

            if (1 === \count($associations)) {
                return [(string) $associations[0]->getTrainer()->getId()];
            }
        }

        return [];
    }

    private function findChildUserOrFail(UserRepository $userRepository, string $id): User
    {
        $childUser = $userRepository->find($this->parseUuid($id));

        if (!$childUser instanceof User) {
            throw $this->createNotFoundException();
        }

        return $childUser;
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
