<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\PlayerGender;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Input to `ChildAccountService::createChild()` (AC-1, AC-2, AC-4, AC-5,
 * AC-6). A plain DTO, not the entity: the service, not the form, decides how
 * these values become a `User` + `ProfilePlayer` + `ChildAccount` (+ zero or
 * more `TrainerPlayerAssociation` rows).
 *
 * `school` and `photo` are optional (AC-1). `trainerIds` is empty when the
 * parent answered "No"/selected no trainer (AC-4) -- `createChild()` then
 * connects nothing. `Task 26`'s `ChildProfileFormType` is the one thing that
 * will ever build one of these from real HTTP input; until then this DTO is
 * constructed directly by callers (tests included).
 *
 * @param list<string> $trainerIds the ids of the trainers the parent selected
 *                                  to connect this child to at creation time,
 *                                  re-validated server-side by the eventual
 *                                  form against the parent's own active
 *                                  associations (Task 26) -- this class does
 *                                  not perform that check itself
 */
final readonly class CreateChildRequest
{
    public function __construct(
        public string $childName,
        public int $age,
        public PlayerGender $gender,
        public ?string $school = null,
        public ?UploadedFile $photo = null,
        public array $trainerIds = [],
    ) {
    }
}
