<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TrainerBrandingRequest;
use PHPUnit\Framework\TestCase;

/**
 * Task 32: `TrainerBrandingRequest`'s one normalisation site -- trim,
 * lowercase, and `''` -> `null` (D4b's flagged risk: three-place hex
 * validation must agree).
 */
final class TrainerBrandingRequestTest extends TestCase
{
    public function testNullIsPreserved(): void
    {
        self::assertNull((new TrainerBrandingRequest(null))->primaryColorHex);
    }

    public function testEmptyStringNormalizesToNull(): void
    {
        self::assertNull((new TrainerBrandingRequest(''))->primaryColorHex);
    }

    public function testWhitespaceOnlyStringNormalizesToNull(): void
    {
        self::assertNull((new TrainerBrandingRequest('   '))->primaryColorHex);
    }

    public function testValueIsTrimmed(): void
    {
        self::assertSame('#ff8800', (new TrainerBrandingRequest('  #ff8800  '))->primaryColorHex);
    }

    public function testValueIsLowercased(): void
    {
        self::assertSame('#ff8800', (new TrainerBrandingRequest('#FF8800'))->primaryColorHex);
    }

    public function testTrimAndLowercaseBothApply(): void
    {
        self::assertSame('#0b5fae', (new TrainerBrandingRequest('  #0B5FAE  '))->primaryColorHex);
    }
}
