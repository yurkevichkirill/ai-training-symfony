<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to connecting an existing child to one more trainer (AC-8), by
 * either a ShareLink code or a direct pick from the parent's own "My
 * Trainers" list. `Task 27`'s `ChildTrainerAddFormType` is what enforces
 * "exactly one of the two present" (an `Assert\Callback`) before this DTO
 * is ever built -- this class itself carries both fields as nullable and
 * does not re-check that invariant.
 *
 * Resolving `$shareLinkCode` (`PlayerShareLinkResolver::resolve()`) and
 * connecting the resulting/looked-up trainer (`ChildTrainerService::connect()`)
 * is `Task 31`'s `ChildTrainerController::add()` job, not this DTO's.
 */
final readonly class AddChildTrainerRequest
{
    public function __construct(
        public ?string $shareLinkCode = null,
        public ?string $trainerId = null,
    ) {
    }
}
