<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\PlayerGender;

/**
 * Input to `PlayerRegistrationService::registerViaShareLink()` (AC-7). A
 * plain DTO, not the entity or the form -- the service decides how these
 * values become a `User` + `ProfilePlayer` + `TrainerPlayerAssociation`,
 * same shape as `CreateTrainerRequest`.
 *
 * `PlayerShareLinkRegistrationFormType` (a later task) is what populates
 * this from HTTP input; nothing here validates -- that is the form's job.
 */
final readonly class PlayerRegistrationRequest
{
    public function __construct(
        public string $email,
        public string $plainPassword,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $phone,
        public string $playerName,
        public int $playerAge,
        public PlayerGender $playerGender,
    ) {
    }
}
