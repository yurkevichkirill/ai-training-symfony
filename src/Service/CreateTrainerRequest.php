<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to `TrainerOnboardingService::createTrainer()` (AC-4). A plain DTO,
 * not the entity: the service, not the form, decides how these values become
 * a `User` + `ProfileTrainer`.
 */
final readonly class CreateTrainerRequest
{
    public function __construct(
        public string $email,
        public string $businessName,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
    ) {
    }
}
