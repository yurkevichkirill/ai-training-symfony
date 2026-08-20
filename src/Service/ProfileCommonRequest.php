<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to `ProfileService::updateCommon()` (AC-10): the fields every role
 * can edit about itself.
 */
final readonly class ProfileCommonRequest
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
    ) {
    }
}
