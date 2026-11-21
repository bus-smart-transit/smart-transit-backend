<?php

namespace App\Support;

/**
 * 2026-11-21 (user): every route has its own colour on the maps and lists, and no two routes share one. A new route
 * takes the first colour of the palette that no route uses yet; once the palette is used up, colours are made by
 * stepping the hue around the colour wheel (the golden angle), skipping any already in use. This is the only place
 * that picks a route's colour.
 */
class RouteColor
{
    /** The first eight are the colours routes 1 to 8 already had on the maps, in the same order. */
    public const PALETTE = [
        '#16a34a', '#2563eb', '#9333ea', '#dc2626', '#0891b2', '#ea580c', '#db2777', '#4d7c0f',
        '#0d9488', '#ca8a04', '#4f46e5', '#92400e', '#881337', '#1e3a8a', '#c026d3', '#65a30d',
    ];

    /**
     * The first colour not in $used (compared without case).
     *
     * @param  array<int, string|null>  $used
     */
    public static function nextFree(array $used): string
    {
        $taken = array_flip(array_map(fn ($color) => strtolower((string) $color), array_filter($used)));
        foreach (self::PALETTE as $color) {
            if (! isset($taken[$color])) {
                return $color;
            }
        }
        for ($step = 1; ; $step++) {
            $color = self::fromHue(fmod($step * 137.508, 360.0));
            if (! isset($taken[$color])) {
                return $color;
            }
        }
    }

    /** A strong, readable colour (saturation 65%, lightness 42%) for the hue, as #rrggbb. */
    private static function fromHue(float $hue): string
    {
        $s = 0.65;
        $l = 0.42;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $hue < 60 => [$c, $x, 0],
            $hue < 120 => [$x, $c, 0],
            $hue < 180 => [0, $c, $x],
            $hue < 240 => [0, $x, $c],
            $hue < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
