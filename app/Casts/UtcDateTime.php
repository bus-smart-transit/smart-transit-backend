<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Batch 28 Stage 3: a timestamp column that is stored as UTC (the new columns of this stage), whatever the
 * application timezone is. Reading gives a Carbon in UTC (toIso8601ZuluString() is then exact); writing converts
 * any Carbon or date string to UTC first. Screens convert to Asia/Manila for display.
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $moment = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value, 'UTC');

        return $moment->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
