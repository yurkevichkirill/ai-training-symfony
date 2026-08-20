<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use App\Form\ProfileCommonFormType;
use App\Form\ProfileTrainerFormType;
use App\Repository\ProfileRepository;
use App\Service\Exception\FileTooLargeException;
use App\Service\Exception\UnsupportedFileTypeException;
use App\Service\ProfileCommonRequest;
use App\Service\ProfileService;
use App\Service\ProfileTrainerRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service profile editing (AC-10, AC-11, AC-12, AC-13). Every action
 * operates on `$this->getUser()` -- never on a route/request-supplied id --
 * which is what makes "cannot edit another user's profile through this
 * form" true by construction rather than by a check that could be missed.
 */
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ProfileService $profileService, ProfileRepository $profileRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $commonForm = $this->createForm(ProfileCommonFormType::class, [
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'phone' => $user->getPhone(),
        ]);
        $commonForm->handleRequest($request);

        if ($commonForm->isSubmitted() && $commonForm->isValid()) {
            /** @var array{firstName: ?string, lastName: ?string, phone: ?string} $data */
            $data = $commonForm->getData();
            $profileService->updateCommon($user, new ProfileCommonRequest($data['firstName'] ?? null, $data['lastName'] ?? null, $data['phone'] ?? null));

            $this->addFlash('success', 'Profile updated.');

            return $this->redirectToRoute('app_profile_edit');
        }

        $trainerForm = null;

        if (UserRole::TRAINER === $user->getRole()) {
            $trainerProfile = $profileRepository->findTrainerProfile($user);

            $trainerForm = $this->createForm(ProfileTrainerFormType::class, [
                'businessName' => $trainerProfile?->getBusinessName() ?? '',
                'website' => $trainerProfile?->getWebsite(),
                'address' => $trainerProfile?->getAddress(),
                'description' => $trainerProfile?->getDescription(),
            ]);
        }

        return $this->render('profile/edit.html.twig', [
            'commonForm' => $commonForm,
            'trainerForm' => $trainerForm,
            'user' => $user,
        ]);
    }

    #[Route('/profile/business', name: 'app_profile_edit_business', methods: ['POST'])]
    public function editBusiness(Request $request, ProfileService $profileService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (UserRole::TRAINER !== $user->getRole()) {
            throw $this->createAccessDeniedException('Only a trainer has business details to edit.');
        }

        $trainerForm = $this->createForm(ProfileTrainerFormType::class);
        $trainerForm->handleRequest($request);

        if ($trainerForm->isSubmitted() && $trainerForm->isValid()) {
            /** @var array{businessName: string, website: ?string, address: ?string, description: ?string} $data */
            $data = $trainerForm->getData();
            $profileService->updateTrainerDetails($user, new ProfileTrainerRequest(
                $data['businessName'],
                $data['website'] ?? null,
                $data['address'] ?? null,
                $data['description'] ?? null,
            ));

            $this->addFlash('success', 'Business details updated.');
        }

        return $this->redirectToRoute('app_profile_edit');
    }

    #[Route('/profile/photo', name: 'app_profile_photo_upload', methods: ['POST'])]
    public function uploadPhoto(Request $request, ProfileService $profileService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $file = $request->files->get('photo');

        if ($file instanceof UploadedFile) {
            try {
                $profileService->uploadPhoto($user, $file);
                $this->addFlash('success', 'Photo updated.');
            } catch (FileTooLargeException|UnsupportedFileTypeException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_profile_edit');
    }
}
