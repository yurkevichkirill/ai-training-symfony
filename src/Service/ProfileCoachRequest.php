<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to `ProfileService::updateCoachDetails()` (AC-11, AC-12, AC-13,
 * AC-16): the coach-specific fields `ProfileCoach` has. Trims `bio`,
 * `credentials` and `certifications` and maps `''` to `null` in the
 * constructor -- edge case 5's whitespace-only rule handled in one place,
 * not per field per caller -- so a whitespace-only submission stores as
 * `NULL`, never as spaces.
 */
final readonly class ProfileCoachRequest
{
    public ?string $bio;
    public ?string $credentials;
    public ?string $certifications;

    public function __construct(
        ?string $bio,
        ?string $credentials,
        ?string $certifications,
        public bool $isPublic = false,
    ) {
        $this->bio = self::trimToNull($bio);
        $this->credentials = self::trimToNull($credentials);
        $this->certifications = self::trimToNull($certifications);
    }

    private static function trimToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
