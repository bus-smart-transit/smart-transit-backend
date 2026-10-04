<?php
namespace App\Services;

use App\Models\FareRule;

class FareCalculationService
{
    // Continuous per-km model, shared by EVERY pricing path in the app:
    // stop-based, GPS custom-point, mid-trip boarding, fleet comparison, and
    // the browsable fare listing. There is exactly one copy of this formula in
    // the whole codebase; nothing else is allowed to reimplement it.
    //
    // Batch 27 Part 2, Task 4: with a base distance, a journey no longer than it
    // costs the base fare, and only the km beyond it are charged per km. Without
    // one (null, as on every existing rule) the formula is the one used so far.
    // To use stepped 5km blocks instead:
    // return round($baseFare + (ceil($distanceKm / 5) * $farePerKm), 2);
    public function computeFare(float $baseFare, float $farePerKm, float $distanceKm, ?float $baseDistanceKm = null): float
    {
        $chargedKm = $baseDistanceKm === null ? $distanceKm : max(0.0, $distanceKm - $baseDistanceKm);

        return round($baseFare + ($chargedKm * $farePerKm), 2);
    }

    /** The one entry point pricing paths use: the rule carries its own base distance. */
    public function priceWithRule(FareRule $rule, float $distanceKm): float
    {
        return $this->computeFare(
            (float) $rule->base_fare,
            (float) $rule->fare_per_km,
            $distanceKm,
            $rule->base_distance_km === null ? null : (float) $rule->base_distance_km,
        );
    }
} 