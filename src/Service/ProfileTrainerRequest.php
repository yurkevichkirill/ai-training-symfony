<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to `ProfileService::updateTrainerDetails()` (AC-11): the business
 * fields only a trainer's profile has.
 */
final readonly class ProfileTrainerRequest
{
    public function __construct(
        public string $businessName,
        public ?string $website = null,
        public ?string $address = null,
        public ?string $description = null,
    ) {
    }
}
