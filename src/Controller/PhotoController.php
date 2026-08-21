<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\FamilyVoter;
use App\Service\FileStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Serves a profile photo through an authorized, per-request check (AC-12) --
 * the opaque `FileStorage` key is never a static, directly-browsable asset
 * URL. A user reads their own photo; a Super Admin reads anyone else's; and
 * (Task 36, AC-1) a parent reads their own child's photo via
 * `FamilyVoter::MANAGE_CHILD`.
 */
#[IsGranted('ROLE_USER')]
final class PhotoController extends AbstractController
{
    #[Route('/photos/{userId}', name: 'app_photo_show', methods: ['GET'])]
    public function show(string $userId, UserRepository $userRepository, FileStorage $fileStorage): Response
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        try {
            $targetUser = $userRepository->find(Uuid::fromString($userId));
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        if (!$targetUser instanceof User) {
            throw $this->createNotFoundException();
        }

        if (!$viewer->getId()->equals($targetUser->getId())
            && !$this->isGranted('ROLE_SUPER_ADMIN')
            && !$this->isGranted(FamilyVoter::MANAGE_CHILD, $targetUser)
        ) {
            throw $this->createAccessDeniedException();
        }

        $photoKey = $targetUser->getPhotoKey();

        if (null === $photoKey) {
            throw $this->createNotFoundException('This user has no photo.');
        }

        return $fileStorage->read($photoKey);
    }
}
