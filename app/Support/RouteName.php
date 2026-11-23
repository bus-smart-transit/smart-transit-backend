<?php

namespace App\Support;

/**
 * 2026-11-20 (user): ONE owner of a route's automatic name. A route created without a name is called
 * "{origin} to {destination}" (the form most production routes already use), with spaces tidied and at most 255
 * characters. The Add route form shows the same name as a preview before saving.
 */
final class RouteName
{
    public static function from(string $origin, string $destination): string
    {
        $tidy = fn (string $place): string => trim((string) preg_replace('/\s+/u', ' ', $place));

        return mb_substr($tidy($origin).' to '.$tidy($destination), 0, 255);
    }

    /**
     * 2026-11-23 (advisor): the key that makes two routes the same road. Case, extra spaces and direction do not count:
     * every schedule runs out and back, so "Nabunturan to Mati" is the same route as "Mati to Nabunturan".
     */
    public static function endsKey(string $origin, string $destination): string
    {
        $norm = fn (string $place): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $place)));
        $ends = [$norm($origin), $norm($destination)];
        sort($ends);

        return implode('|', $ends);
    }
}
