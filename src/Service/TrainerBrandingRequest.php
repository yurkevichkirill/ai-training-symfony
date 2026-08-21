<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to `TrainerBrandingService::updateColor()` (AC-9). In
 * `ProfileCoachRequest`'s constructor-normalising style: trims and
 * lowercases the hex, mapping `''` to `null` -- one normalisation site, not
 * per-caller, so `TrainerBrandingFormType`, a future API controller, and a
 * console command all agree on what "no value" means (D4b's flagged risk:
 * three-place hex validation must agree).
 */
final readonly class TrainerBrandingRequest
{
    public ?string $primaryColorHex;

    public function __construct(?string $primaryColorHex)
    {
        $this->primaryColorHex = self::normalize($primaryColorHex);
    }

    private static function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        return '' === $trimmed ? null : $trimmed;
    }
}
