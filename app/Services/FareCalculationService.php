<?php
namespace App\Services;

class FareCalculationService
{
    // Continuous per-km model, shared by EVERY pricing path in the app —
    // stop-based, GPS custom-point, fleet comparison, and the browsable
    // fare listing. There is exactly one copy of this formula in the whole
    // codebase; nothing else is allowed to reimplement it.
    // To use stepped 5km blocks instead:
    // return round($baseFare + (ceil($distanceKm / 5) * $farePerKm), 2);
    public function computeFare(float $baseFare, float $farePerKm, float $distanceKm): float
    {
        return round($baseFare + ($distanceKm * $farePerKm), 2);
    }
} 