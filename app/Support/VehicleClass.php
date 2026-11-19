<?php

namespace App\Support;

/**
 * Batch 28 item 5a: the fleet class list comes from config('fares.vehicle_classes'); nothing here names a class.
 */
final class VehicleClass
{
    /** @return array<string, string> key => label */
    public static function all(): array
    {
        return (array) config('fares.vehicle_classes', []);
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** The stored key for an input (aliases applied, case and spaces ignored); null when it is not a known class. */
    public static function normalize(?string $value): ?string
    {
        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return null;
        }
        $key = (string) (config('fares.vehicle_class_aliases')[$key] ?? $key);

        return array_key_exists($key, self::all()) ? $key : null;
    }

    public static function label(?string $key): ?string
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }
}
