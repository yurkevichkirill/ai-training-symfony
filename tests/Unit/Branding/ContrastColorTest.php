<?php

declare(strict_types=1);

namespace App\Tests\Unit\Branding;

use App\Branding\ContrastColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 32: WCAG 2.x relative-luminance contrast, parameterized across
 * white, black, the platform default, a pale yellow (must choose the dark
 * text), a mid-grey on either side of the crossover, and the three
 * primaries -- asserting the chosen pair meets 4.5:1.
 */
final class ContrastColorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function backgrounds(): iterable
    {
        yield 'white background chooses dark text' => ['#ffffff', '#1a1a1a'];
        yield 'black background chooses white text' => ['#000000', '#ffffff'];
        yield 'platform default blue chooses white text' => ['#0b5fae', '#ffffff'];
        yield 'pale yellow chooses dark text' => ['#ffff99', '#1a1a1a'];
        yield 'a light-mid grey chooses dark text' => ['#aaaaaa', '#1a1a1a'];
        yield 'a dark-mid grey chooses white text' => ['#555555', '#ffffff'];
        yield 'a deep red primary chooses white text' => ['#cc0000', '#ffffff'];
        yield 'a deep green primary chooses white text' => ['#008000', '#ffffff'];
        yield 'a deep blue primary chooses white text' => ['#0000cc', '#ffffff'];
    }

    #[DataProvider('backgrounds')]
    public function testChoosesTheHigherContrastOption(string $background, string $expected): void
    {
        self::assertSame($expected, ContrastColor::forBackground($background));
    }

    #[DataProvider('backgrounds')]
    public function testChosenPairMeetsWcagAaContrastOfAtLeast4Point5To1(string $background, string $expected): void
    {
        $chosen = ContrastColor::forBackground($background);
        self::assertSame($expected, $chosen);

        $ratio = self::contrastRatio($background, $chosen);

        self::assertGreaterThanOrEqual(4.5, $ratio, \sprintf('%s against %s must meet 4.5:1', $chosen, $background));
    }

    private static function contrastRatio(string $backgroundHex, string $textHex): float
    {
        $backgroundLuminance = self::relativeLuminance($backgroundHex);
        $textLuminance = self::relativeLuminance($textHex);

        $lighter = max($backgroundLuminance, $textLuminance);
        $darker = min($backgroundLuminance, $textLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $r = self::linearize((int) hexdec(substr($hex, 0, 2)));
        $g = self::linearize((int) hexdec(substr($hex, 2, 2)));
        $b = self::linearize((int) hexdec(substr($hex, 4, 2)));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function linearize(int $channel): float
    {
        $normalized = $channel / 255.0;

        return $normalized <= 0.03928
            ? $normalized / 12.92
            : (($normalized + 0.055) / 1.055) ** 2.4;
    }
}
