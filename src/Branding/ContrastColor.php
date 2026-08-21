<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * WCAG 2.x relative-luminance contrast, stateless and dependency-free
 * (S7, D4b). The whole reason a trainer cannot ship an unreadable button by
 * picking a pale brand colour: the contrast pair is derived, never picked
 * by the trainer and never stored.
 */
final class ContrastColor
{
    private const WHITE = '#ffffff';

    /** The stylesheet's own `--color-text` (S7, D4b). */
    private const DARK_TEXT = '#1a1a1a';

    private function __construct()
    {
    }

    /**
     * Returns whichever of `#ffffff` / `#1a1a1a` has the higher contrast
     * ratio against `$hex`, per WCAG 2.x's relative-luminance formula
     * (sRGB linearisation, 0.2126/0.7152/0.0722 weights).
     */
    public static function forBackground(string $hex): string
    {
        $backgroundLuminance = self::relativeLuminance($hex);
        $whiteLuminance = self::relativeLuminance(self::WHITE);
        $darkLuminance = self::relativeLuminance(self::DARK_TEXT);

        $contrastWithWhite = self::contrastRatio($backgroundLuminance, $whiteLuminance);
        $contrastWithDark = self::contrastRatio($backgroundLuminance, $darkLuminance);

        return $contrastWithWhite >= $contrastWithDark ? self::WHITE : self::DARK_TEXT;
    }

    private static function contrastRatio(float $luminanceA, float $luminanceB): float
    {
        $lighter = max($luminanceA, $luminanceB);
        $darker = min($luminanceA, $luminanceB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        [$red, $green, $blue] = self::rgb($hex);

        $r = self::linearize($red);
        $g = self::linearize($green);
        $b = self::linearize($blue);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function linearize(int $channel): float
    {
        $normalized = $channel / 255.0;

        return $normalized <= 0.03928
            ? $normalized / 12.92
            : (($normalized + 0.055) / 1.055) ** 2.4;
    }
}
