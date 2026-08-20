<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Repository\ProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Self-service profile editing (AC-10, AC-11, AC-12). Every method takes the
 * `User` to act on as an explicit argument -- callers (ProfileController)
 * always pass `$this->getUser()`, never a request-supplied id, which is what
 * makes AC-13's "cannot spoof a different user's id" true by construction.
 */
final class ProfileService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ProfileRepository $profileRepository,
        private readonly FileStorage $fileStorage,
        private readonly AccountEventRecorder $accountEventRecorder,
    ) {
    }

    /**
     * $actor defaults to $user itself (a self-edit via ProfileController).
     * When a Super Admin edits another account through the Users tool
     * (AC-13), the caller passes the admin as $actor so the audit trail
     * distinguishes "edited their own profile" from "was edited by an
     * admin."
     */
    public function updateCommon(User $user, ProfileCommonRequest $request, ?User $actor = null): void
    {
        $user->setName($request->firstName, $request->lastName);
        $user->setPhone($request->phone);
        $user->touch();

        $this->flush($user);
        $this->recordProfileUpdated($user, $actor ?? $user);
    }

    public function updateTrainerDetails(User $user, ProfileTrainerRequest $request, ?User $actor = null): void
    {
        $profile = $this->profileRepository->findTrainerProfile($user);

        if (!$profile instanceof ProfileTrainer) {
            throw new \LogicException('This user has no trainer profile to update.');
        }

        $profile->setBusinessName($request->businessName);
        $profile->setWebsite($request->website);
        $profile->setAddress($request->address);
        $profile->setDescription($request->description);
        $profile->touch();

        $this->flush($profile);
        $this->recordProfileUpdated($user, $actor ?? $user);
    }

    /**
     * Not audit-worthy on its own (unlike the common/trainer edits above):
     * a photo is not a PII disclosure or an access change, so no
     * `AccountEvent` is recorded here.
     */
    public function uploadPhoto(User $user, UploadedFile $file): string
    {
        $previousKey = $user->getPhotoKey();
        $newKey = $this->fileStorage->store($file, 'photos');

        $user->setPhotoKey($newKey);
        $user->touch();
        $this->flush($user);

        if (null !== $previousKey) {
            $this->fileStorage->delete($previousKey);
        }

        return $newKey;
    }

    private function flush(object $entity): void
    {
        $entityManager = $this->managerRegistry->getManagerForClass($entity::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->flush();
    }

    private function recordProfileUpdated(User $subject, User $actor): void
    {
        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::PROFILE_UPDATED,
            actorUserId: $actor->getId(),
            subjectUserId: $subject->getId(),
        ));
    }
}
