<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * The render-ready shape of one trainer's branding (S7, D4, D4b). Doctrine-
 * free and holds no entity reference, so a template can never reach through
 * it into the ORM and it is unit-testable with no kernel.
 */
final readonly class TrainerBranding
{
    private const DEFAULT_PRIMARY_COLOR_HEX = '#0b5fae';
    private const DEFAULT_CONTRAST_COLOR_HEX = '#ffffff';

    public function __construct(
        public ?string $logoUrl,
        public string $primaryColorHex,
        public string $contrastColorHex,
    ) {
    }

    /**
     * The platform default -- `public/css/app.css`'s existing
     * `--color-primary: #0b5fae; --color-primary-contrast: #ffffff;` --
     * for a trainer who has never customised anything, or after a reset
     * (AC-10).
     */
    public static function platformDefault(): self
    {
        return new self(
            logoUrl: null,
            primaryColorHex: self::DEFAULT_PRIMARY_COLOR_HEX,
            contrastColorHex: self::DEFAULT_CONTRAST_COLOR_HEX,
        );
    }

    public function hasLogo(): bool
    {
        return null !== $this->logoUrl;
    }
}
