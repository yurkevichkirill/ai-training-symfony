<?php

declare(strict_types=1);

namespace App\Controller\Trainer;

use App\Entity\User;
use App\Form\TrainerBrandingFormType;
use App\Repository\ProfileRepository;
use App\Security\BrandingVoter;
use App\Service\Exception\BrandingActionNotPermittedException;
use App\Service\Exception\FileTooLargeException;
use App\Service\Exception\UnprocessableImageException;
use App\Service\Exception\UnsupportedFileTypeException;
use App\Service\TrainerBrandingRequest;
use App\Service\TrainerBrandingResolver;
use App\Service\TrainerBrandingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A trainer's own "My Portal Settings" / "Branding" page (AC-1, AC-2,
 * AC-8, AC-9, AC-10). `#[IsGranted('ROLE_TRAINER')]` at the class level is
 * S1's belt-and-braces rule every `Trainer\*Controller` follows; it also
 * happens to be what refuses a Super Admin hitting this self-service route
 * directly -- `BrandingVoter::EDIT_BRANDING` is what carries the admin
 * allowance for a *named* trainer elsewhere, not this route.
 * `denyAccessUnlessGranted` runs before handling either verb in every
 * action, ahead of `TrainerBrandingService`'s own defence-in-depth guard.
 */
#[IsGranted('ROLE_TRAINER')]
final class BrandingController extends AbstractController
{
    #[Route('/trainer/branding', name: 'app_trainer_branding', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        TrainerBrandingService $brandingService,
        TrainerBrandingResolver $brandingResolver,
        ProfileRepository $profileRepository,
    ): Response {
        /** @var User $trainer */
        $trainer = $this->getUser();

        $this->denyAccessUnlessGranted(BrandingVoter::EDIT_BRANDING, $trainer);

        $trainerProfile = $profileRepository->findTrainerProfile($trainer);

        $form = $this->createForm(TrainerBrandingFormType::class, [
            'primaryColorHex' => $trainerProfile?->getPrimaryColorHex(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{primaryColorHex: ?string} $data */
            $data = $form->getData();

            $brandingService->updateColor($trainer, new TrainerBrandingRequest($data['primaryColorHex'] ?? null), $trainer);

            $this->addFlash('success', 'Branding updated.');

            return $this->redirectToRoute('app_trainer_branding');
        }

        return $this->render('trainer/branding/edit.html.twig', [
            'form' => $form,
            'branding' => $brandingResolver->forTrainer($trainer),
        ]);
    }

    #[Route('/trainer/branding/logo', name: 'app_trainer_branding_logo', methods: ['POST'])]
    public function uploadLogo(Request $request, TrainerBrandingService $brandingService): Response
    {
        /** @var User $trainer */
        $trainer = $this->getUser();

        $this->denyAccessUnlessGranted(BrandingVoter::EDIT_BRANDING, $trainer);

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $file = $request->files->get('logo');

        if ($file instanceof UploadedFile) {
            try {
                $brandingService->uploadLogo($trainer, $file, $trainer);
                $this->addFlash('success', 'Logo updated.');
            } catch (FileTooLargeException|UnsupportedFileTypeException|UnprocessableImageException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (BrandingActionNotPermittedException) {
                throw $this->createAccessDeniedException();
            }
        }

        return $this->redirectToRoute('app_trainer_branding');
    }

    #[Route('/trainer/branding/reset', name: 'app_trainer_branding_reset', methods: ['POST'])]
    public function reset(Request $request, TrainerBrandingService $brandingService): Response
    {
        /** @var User $trainer */
        $trainer = $this->getUser();

        $this->denyAccessUnlessGranted(BrandingVoter::EDIT_BRANDING, $trainer);

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $brandingService->resetToDefault($trainer, $trainer);
            $this->addFlash('success', 'Branding reset to the platform default.');
        } catch (BrandingActionNotPermittedException) {
            throw $this->createAccessDeniedException();
        }

        return $this->redirectToRoute('app_trainer_branding');
    }
}
