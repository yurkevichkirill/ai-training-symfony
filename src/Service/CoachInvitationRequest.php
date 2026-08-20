<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to `CoachInvitationService::invite()` (AC-5, AC-19). A plain DTO,
 * not the entity or the form -- same shape as `CreateTrainerRequest` and
 * `PlayerRegistrationRequest`. `email` is the only required field (AC-19);
 * `CoachInvitationFormType` (a later task) is what populates this from HTTP
 * input and enforces that -- nothing here validates.
 */
final readonly class CoachInvitationRequest
{
    public function __construct(
        public string $email,
        public ?string $name = null,
        public ?string $message = null,
    ) {
    }
}
