<?php
namespace App\Services;

use App\Models\FareRule;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class FareCalculationService
{
    // Continuous per-km model, shared by EVERY pricing path in the app:
    // stop-based, GPS custom-point, mid-trip boarding, fleet comparison, and
    // the browsable fare listing. There is exactly one copy of this formula in
    // the whole codebase; nothing else is allowed to reimplement it.
    //
    // distance <= base distance  ->  base fare
    // otherwise                  ->  base fare + (distance - base distance) x fare per km
    // No base distance (null, as on every rule created before it existed) means the plain
    // base fare + distance x fare per km.
    //
    // Batch 28 item 5: the arithmetic is decimal (no float drift) and the result is rounded ONCE, at the end, to
    // config('fares.rounding_increment') by the rule's rounding_mode: nearest (half up) or ceiling.
    // To use stepped 5km blocks instead:
    // return round($baseFare + (ceil($distanceKm / 5) * $farePerKm), 2);
    public function computeFare(float $baseFare, float $farePerKm, float $distanceKm, ?float $baseDistanceKm = null, ?string $roundingMode = null): float
    {
        $base = $this->decimal($baseFare);
        $perKm = $this->decimal($farePerKm);
        $distance = $this->decimal($distanceKm);

        $chargedKm = $distance;
        if ($baseDistanceKm !== null) {
            $chargedKm = $distance->minus($this->decimal($baseDistanceKm));
            if ($chargedKm->isNegative()) {
                $chargedKm = BigDecimal::zero();
            }
        }

        $raw = $base->plus($chargedKm->multipliedBy($perKm));

        return $this->round($raw, $roundingMode)->toFloat();
    }

    /**
     * Batch 28 Stage 3 (item 6): the same single rounding step, for an amount that is not a fare formula result,
     * e.g. a refund (ticket fare minus the fare of the ridden portion). Whole pesos while the increment is 1.
     */
    public function roundAmount(float $amount, ?string $roundingMode = null): float
    {
        return $this->round($this->decimal($amount), $roundingMode)->toFloat();
    }

    /** The one entry point pricing paths use: the rule carries its own base distance and rounding mode. */
    public function priceWithRule(FareRule $rule, float $distanceKm): float
    {
        return $this->computeFare(
            (float) $rule->base_fare,
            (float) $rule->fare_per_km,
            $distanceKm,
            $rule->base_distance_km === null ? null : (float) $rule->base_distance_km,
            $rule->rounding_mode,
        );
    }

    /** Round once, at the end, to the configured increment. Unknown modes behave as the default (nearest). */
    private function round(BigDecimal $amount, ?string $mode): BigDecimal
    {
        $increment = BigDecimal::of((string) config('fares.rounding_increment', '0.01'));
        if (! $increment->isPositive()) {
            $increment = BigDecimal::of('0.01');
        }

        $steps = $amount->dividedBy($increment, 12, RoundingMode::HALF_UP);
        $steps = $steps->toScale(0, strtolower((string) $mode) === 'ceiling' ? RoundingMode::CEILING : RoundingMode::HALF_UP);

        return $steps->multipliedBy($increment)->toScale(max(2, $increment->getScale()), RoundingMode::HALF_UP);
    }

    /** Floats come from double columns and the leg arithmetic: take them at 4-decimal precision. */
    private function decimal(float $value): BigDecimal
    {
        return BigDecimal::of(number_format($value, 4, '.', ''));
    }
}
