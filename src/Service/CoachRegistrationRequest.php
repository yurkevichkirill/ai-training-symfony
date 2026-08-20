<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to `CoachRegistrationService::registerAndAccept()` (AC-14, AC-21). A
 * plain DTO, not the entity or the form -- same shape as
 * `PlayerRegistrationRequest`. Deliberately **no `email` field**: the
 * account's email is always read from the `CoachInvitation` being accepted,
 * never from request input, which is what makes AC-21 structural on this
 * branch rather than a check that could be missed (architecture Decisions
 * Q4). `CoachRegistrationFormType` (a later task) is what populates this
 * from HTTP input; nothing here validates.
 */
final readonly class CoachRegistrationRequest
{
    public function __construct(
        public string $plainPassword,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $phone,
    ) {
    }
}
