<?php
namespace App\Repositories;
use App\Models\FareRule;
use App\Models\FleetRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FareRuleRepository
{
    public function create(array $payload): FareRule
    {
        return FareRule::create($payload);
    }

    /**
     * Batch 27 Part 2: only rules in force on a date. A null effective_from / effective_to is open ended,
     * so every rule that existed before the columns keeps applying. The date is the Manila calendar
     * date of the trip (default: today). When two rules overlap, the one that started latest wins.
     */
    private function inForce(Builder $query, ?string $on): Builder
    {
        $date = $on ?: Carbon::now(config('app.timezone', 'Asia/Manila'))->toDateString();

        return $query
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByRaw('CASE WHEN effective_from IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('effective_from')
            ->orderByDesc('updated_at');
    }

    public function getActiveRulesForFleet(int $fleetId, ?string $on = null): Collection
    {
        return $this->inForce(FareRule::where('fleet_id', $fleetId), $on)->get();
    }

    public function getActiveRule(int $fleetId, string $seatType, ?string $on = null): ?FareRule
    {
        $exact = $this->inForce(FareRule::where('fleet_id', $fleetId)->where('seat_type', $seatType), $on)->first();

        if ($exact) {
            return $exact;
        }

        // Legacy safety fallback: if older rows used a wrong seat_type
        // value, still return one active rule so checkout can proceed.
        return $this->inForce(FareRule::where('fleet_id', $fleetId), $on)->first();
    }

    public function getActiveRuleForRoute(int $routeId, string $seatType, ?string $on = null): ?FareRule
    {
        $fleetIds = FleetRoute::where('route_id', $routeId)
            ->where('status', 'active')
            ->pluck('fleet_id')
            ->all();

        if (empty($fleetIds)) {
            return null;
        }

        $exact = $this->inForce(FareRule::whereIn('fleet_id', $fleetIds)->where('seat_type', $seatType), $on)->first();

        if ($exact) {
            return $exact;
        }

        return $this->inForce(FareRule::whereIn('fleet_id', $fleetIds), $on)->first();
    }

    /** True when another active rule of this fleet and seat type is in force on any day of the range. */
    public function overlappingRuleExists(int $fleetId, string $seatType, ?string $from, ?string $to): bool
    {
        return FareRule::where('fleet_id', $fleetId)
            ->where('seat_type', $seatType)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $to ?? '9999-12-31'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from ?? '0001-01-01'))
            ->exists();
    }

    public function update(int $fareRuleId, array $payload): bool
    {
        return FareRule::where('fare_rule_id', $fareRuleId)->update($payload) > 0;
    }
}