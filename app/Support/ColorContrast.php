<?php

namespace App\Support;

/**
 * WCAG 2 contrast, for picking readable text on a colour a Superadmin chose
 * (the navbar, Phase 16). Pure — no framework — so it's unit-tested
 * directly.
 */
final class ColorContrast
{
    /** Tailwind's gray-900: the dark text the navbar uses on light colours. */
    public const DARK_TEXT = '#111827';

    public const LIGHT_TEXT = '#ffffff';

    /** WCAG relative luminance of a #rrggbb colour, 0 (black) to 1 (white). */
    public static function luminance(string $hex): float
    {
        $channels = array_map(
            static function (string $pair): float {
                $c = hexdec($pair) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** WCAG contrast ratio between two colours, 1 to 21. */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Whether light text reads better than dark text on $background. */
    public static function prefersLightText(string $background): bool
    {
        return self::contrastRatio($background, self::LIGHT_TEXT) > self::contrastRatio($background, self::DARK_TEXT);
    }
}
