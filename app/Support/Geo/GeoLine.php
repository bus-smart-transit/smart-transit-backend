<?php

namespace App\Support\Geo;

/**
 * Pure-PHP geometry helpers over GeoJSON coordinates ([lng, lat] order).
 * One implementation shared by snapping, validation and reporting so the
 * client never computes authoritative positions.
 */
class GeoLine
{
    private const EARTH_RADIUS_M = 6371008.8;

    public static function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $coords  [lng, lat] pairs
     */
    public static function lengthM(array $coords): float
    {
        $total = 0.0;
        for ($i = 0; $i < count($coords) - 1; $i++) {
            $total += self::haversineM($coords[$i][1], $coords[$i][0], $coords[$i + 1][1], $coords[$i + 1][0]);
        }

        return $total;
    }

    /**
     * Nearest point on the polyline to (lat, lng).
     *
     * @param  array<int, array{0: float, 1: float}>  $coords
     * @return array{latitude: float, longitude: float, distance_to_line_m: float, distance_along_m: float, total_length_m: float}|null
     */
    public static function project(array $coords, float $lat, float $lng): ?array
    {
        if (count($coords) < 2) {
            return null;
        }

        $cosLat = cos(deg2rad($lat));
        $best = null;
        $travelled = 0.0;

        for ($i = 0; $i < count($coords) - 1; $i++) {
            [$ax, $ay] = $coords[$i];
            [$bx, $by] = $coords[$i + 1];
            $segLen = self::haversineM($ay, $ax, $by, $bx);

            // Local equirectangular frame for the projection parameter only.
            $abx = ($bx - $ax) * $cosLat;
            $aby = $by - $ay;
            $apx = ($lng - $ax) * $cosLat;
            $apy = $lat - $ay;
            $lenSq = $abx * $abx + $aby * $aby;
            $t = $lenSq > 0 ? max(0.0, min(1.0, ($apx * $abx + $apy * $aby) / $lenSq)) : 0.0;

            $cx = $ax + $t * ($bx - $ax);
            $cy = $ay + $t * ($by - $ay);
            $dist = self::haversineM($lat, $lng, $cy, $cx);

            if ($best === null || $dist < $best['distance_to_line_m']) {
                $best = [
                    'latitude' => $cy,
                    'longitude' => $cx,
                    'distance_to_line_m' => $dist,
                    'distance_along_m' => $travelled + $t * $segLen,
                ];
            }

            $travelled += $segLen;
        }

        $best['total_length_m'] = $travelled;

        return $best;
    }

    /**
     * The part of the polyline between two distances along it (metres from its first point), with the two ends
     * interpolated on the segments they fall in. Used to draw a stretch of the route (a restricted area) on a map.
     *
     * @param  array<int, array{0: float, 1: float}>  $coords  [lng, lat] pairs
     * @return array<int, array{0: float, 1: float}>
     */
    public static function slice(array $coords, float $fromM, float $toM): array
    {
        if (count($coords) < 2 || $toM <= $fromM) {
            return [];
        }

        $out = [];
        $travelled = 0.0;
        for ($i = 0; $i < count($coords) - 1; $i++) {
            [$ax, $ay] = $coords[$i];
            [$bx, $by] = $coords[$i + 1];
            $segLen = self::haversineM($ay, $ax, $by, $bx);
            $segStart = $travelled;
            $segEnd = $travelled + $segLen;
            $travelled = $segEnd;

            if ($segEnd < $fromM || $segStart > $toM || $segLen <= 0.0) {
                continue;
            }

            $at = fn (float $d) => [$ax + (($d - $segStart) / $segLen) * ($bx - $ax), $ay + (($d - $segStart) / $segLen) * ($by - $ay)];
            $start = max($fromM, $segStart);
            $end = min($toM, $segEnd);

            if ($out === []) {
                $out[] = $at($start);
            }
            $out[] = $at($end);
        }

        return $out;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $coords
     * @return array<int, array{0: float, 1: float}>
     */
    public static function reverse(array $coords): array
    {
        return array_values(array_reverse($coords));
    }
}
