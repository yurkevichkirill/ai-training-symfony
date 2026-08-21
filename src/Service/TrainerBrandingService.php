<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Repository\ProfileRepository;
use App\Service\Exception\BrandingActionNotPermittedException;
use App\Service\Exception\UnprocessableImageException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The only writer of `ProfileTrainer::$logoKey`/`$primaryColorHex` (S7).
 * Every method opens with the same guard (AC-2, AC-3, AC-4, AC-5, AC-8,
 * AC-9, AC-10, BR-001): `$trainer` must be an active `UserRole::TRAINER`
 * with a `ProfileTrainer`, and `$actor` must be `$trainer` or an active
 * `SUPER_ADMIN`, else `BrandingActionNotPermittedException` -- defence in
 * depth behind `BrandingVoter`, per S3/S5's convention, so a console
 * command or future API controller cannot bypass the rule. `ProfileService`
 * is deliberately not extended (D6): it is keyed on "the signed-in user's
 * own profile", and branding has a Super-Admin-acts-on-a-named-trainer
 * path.
 */
final class TrainerBrandingService
{
    /** @var array<string, string> */
    private const LOGO_MIME_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private const LOGO_MAX_BYTES = 2 * 1024 * 1024;
    private const LOGO_MAX_DIMENSION_PX = 4000;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ProfileRepository $profileRepository,
        private readonly FileStorage $fileStorage,
        private readonly AccountEventRecorder $accountEventRecorder,
    ) {
    }

    /**
     * @throws BrandingActionNotPermittedException
     * @throws UnprocessableImageException
     * @throws \App\Service\Exception\FileTooLargeException
     * @throws \App\Service\Exception\UnsupportedFileTypeException
     */
    public function uploadLogo(User $trainer, UploadedFile $file, User $actor): string
    {
        $profile = $this->guardedProfile($trainer, $actor);

        $this->assertProcessableImage($file);

        $newKey = $this->fileStorage->store($file, 'branding', self::LOGO_MAX_BYTES, self::LOGO_MIME_TYPES);

        // Store-then-clean order, matching ProfileService::uploadPhoto()
        // exactly, so a failed flush leaves the old logo intact rather than
        // no logo at all.
        $previousKey = $profile->getLogoKey();
        $profile->setLogoKey($newKey);
        $profile->touch();
        $this->flush($profile);

        if (null !== $previousKey) {
            $this->fileStorage->delete($previousKey);
        }

        $this->recordProfileUpdated($trainer, $actor);

        return $newKey;
    }

    /**
     * @throws BrandingActionNotPermittedException
     */
    public function updateColor(User $trainer, TrainerBrandingRequest $request, User $actor): void
    {
        $profile = $this->guardedProfile($trainer, $actor);

        $profile->setPrimaryColorHex($request->primaryColorHex);
        $profile->touch();
        $this->flush($profile);

        $this->recordProfileUpdated($trainer, $actor);
    }

    /**
     * @throws BrandingActionNotPermittedException
     */
    public function removeLogo(User $trainer, User $actor): void
    {
        $profile = $this->guardedProfile($trainer, $actor);

        $previousKey = $profile->getLogoKey();
        $profile->setLogoKey(null);
        $profile->touch();
        $this->flush($profile);

        if (null !== $previousKey) {
            $this->fileStorage->delete($previousKey);
        }

        $this->recordProfileUpdated($trainer, $actor);
    }

    /**
     * AC-10: clears **both** columns in one flush, then deletes the logo
     * file afterward if one existed -- the trainer is returned to exactly
     * the state a trainer who never customised anything is in; there is no
     * third "customised but reverted" state to represent (D1b).
     *
     * @throws BrandingActionNotPermittedException
     */
    public function resetToDefault(User $trainer, User $actor): void
    {
        $profile = $this->guardedProfile($trainer, $actor);

        $previousKey = $profile->getLogoKey();
        $profile->setLogoKey(null);
        $profile->setPrimaryColorHex(null);
        $profile->touch();
        $this->flush($profile);

        if (null !== $previousKey) {
            $this->fileStorage->delete($previousKey);
        }

        $this->recordProfileUpdated($trainer, $actor);
    }

    /**
     * @throws BrandingActionNotPermittedException
     */
    private function guardedProfile(User $trainer, User $actor): ProfileTrainer
    {
        if (UserRole::TRAINER !== $trainer->getRole() || !$trainer->isActive()) {
            throw new BrandingActionNotPermittedException();
        }

        $profile = $this->profileRepository->findTrainerProfile($trainer);

        if (!$profile instanceof ProfileTrainer) {
            throw new BrandingActionNotPermittedException();
        }

        $isActorTheTrainer = $actor === $trainer;
        $isActorAnActiveSuperAdmin = UserRole::SUPER_ADMIN === $actor->getRole() && $actor->isActive();

        if (!$isActorTheTrainer && !$isActorAnActiveSuperAdmin) {
            throw new BrandingActionNotPermittedException();
        }

        return $profile;
    }

    /**
     * A second, independent decoder's opinion on top of `FileStorage`'s
     * finfo-backed MIME sniff, and a decompression-bomb guard (AC-5, D2c):
     * a 40-megapixel PNG can sit well under the 2MB cap. Run *before* the
     * store call, so a rejected image is never moved into storage.
     *
     * @throws UnprocessableImageException
     */
    private function assertProcessableImage(UploadedFile $file): void
    {
        $dimensions = @getimagesize($file->getPathname());

        if (false === $dimensions) {
            throw new UnprocessableImageException();
        }

        [$width, $height] = $dimensions;

        if ($width > self::LOGO_MAX_DIMENSION_PX || $height > self::LOGO_MAX_DIMENSION_PX) {
            throw new UnprocessableImageException();
        }
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
