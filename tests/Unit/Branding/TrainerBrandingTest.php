<?php

declare(strict_types=1);

namespace App\Tests\Unit\Branding;

use App\Branding\TrainerBranding;
use PHPUnit\Framework\TestCase;

/**
 * Task 32: `TrainerBranding::platformDefault()` returns `#0b5fae`/`#ffffff`
 * and `hasLogo() === false`; a branding with a logo URL reports `true`.
 */
final class TrainerBrandingTest extends TestCase
{
    public function testPlatformDefaultReturnsTheStylesheetDefaultsWithNoLogo(): void
    {
        $branding = TrainerBranding::platformDefault();

        self::assertSame('#0b5fae', $branding->primaryColorHex);
        self::assertSame('#ffffff', $branding->contrastColorHex);
        self::assertNull($branding->logoUrl);
        self::assertFalse($branding->hasLogo());
    }

    public function testHasLogoIsTrueWhenALogoUrlIsPresent(): void
    {
        $branding = new TrainerBranding(
            logoUrl: '/branding/logo/00000000-0000-0000-0000-000000000000',
            primaryColorHex: '#ff8800',
            contrastColorHex: '#1a1a1a',
        );

        self::assertTrue($branding->hasLogo());
    }
}
