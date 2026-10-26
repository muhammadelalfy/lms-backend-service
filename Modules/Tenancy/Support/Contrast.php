<?php

declare(strict_types=1);

namespace Modules\Tenancy\Support;

/** WCAG 2.x contrast between two #RRGGBB colors, and the text color that reads on a background. */
final class Contrast
{
    /** The least ratio WCAG AA asks of normal text. */
    public const AA = 4.5;

    public static function ratio(string $foreground, string $background): float
    {
        $a = self::luminance($foreground);
        $b = self::luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** White when white text passes AA on [$background], else black. */
    public static function textOn(string $background): string
    {
        return self::ratio('#FFFFFF', $background) >= self::AA ? '#FFFFFF' : '#000000';
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(fn (string $pair) => hexdec($pair) / 255, str_split(substr($hex, 0, 6), 2));
        [$r, $g, $b] = array_map(fn (float $c) => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
