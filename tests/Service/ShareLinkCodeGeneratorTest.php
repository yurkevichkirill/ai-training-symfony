<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ShareLinkCodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for `ShareLinkCodeGenerator` (Task 29, AC-1, AC-2): its
 * fixed length and base64url alphabet -- `random_bytes(9)` base64-encodes to
 * exactly 12 characters with no padding, since 9 is a multiple of 3.
 */
final class ShareLinkCodeGeneratorTest extends TestCase
{
    private ShareLinkCodeGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ShareLinkCodeGenerator();
    }

    public function testGenerateProducesATwelveCharacterCode(): void
    {
        self::assertSame(12, \strlen($this->generator->generate()));
    }

    /**
     * Base64url: `A-Z`, `a-z`, `0-9`, `-`, `_` -- never `+`, `/`, or `=`.
     */
    public function testGenerateProducesOnlyBase64UrlAlphabetCharacters(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $code = $this->generator->generate();
            self::assertMatchesRegularExpression(
                '/^[A-Za-z0-9_-]{12}$/',
                $code,
                \sprintf('Code "%s" contains a character outside the base64url alphabet.', $code),
            );
        }
    }

    /**
     * 9 bytes is a multiple of 3, so base64 encoding never needs padding --
     * this asserts that directly rather than merely inferring it from the
     * length check above.
     */
    public function testGenerateNeverProducesPaddingCharacters(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            self::assertStringNotContainsString('=', $this->generator->generate());
        }
    }

    public function testGenerateProducesDistinctCodesAcrossManyCalls(): void
    {
        $codes = [];
        for ($i = 0; $i < 500; ++$i) {
            $codes[] = $this->generator->generate();
        }

        self::assertCount(
            500,
            array_unique($codes),
            'A collision within 500 calls would be far more frequent than the birthday bound for a 9-byte (72-bit) random value predicts -- a sign the generator is not actually using a full-entropy source.',
        );
    }
}
