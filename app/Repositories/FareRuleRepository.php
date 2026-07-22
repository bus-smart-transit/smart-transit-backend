<?php
namespace App\Repositories;
use App\Models\FareRule;
use App\Models\FleetRoute;
use Illuminate\Support\Collection;

class FareRuleRepository
{
    public function create(array $payload): FareRule
    {
        return FareRule::create($payload);
    }

    public function getActiveRulesForFleet(int $fleetId): Collection
    {
        return FareRule::where('fleet_id', $fleetId)
            ->where('status', 'active')
            ->get();
    }

    public function getActiveRule(int $fleetId, string $seatType): ?FareRule
    {
        $exact = FareRule::where('fleet_id', $fleetId)
            ->where('seat_type', $seatType)
            ->where('status', 'active')
            ->first();

        if ($exact) {
            return $exact;
        }

        // Legacy safety fallback: if older rows used a wrong seat_type
        // value, still return one active rule so checkout can proceed.
        return FareRule::where('fleet_id', $fleetId)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();
    }

    public function getActiveRuleForRoute(int $routeId, string $seatType): ?FareRule
    {
        $fleetIds = FleetRoute::where('route_id', $routeId)
            ->where('status', 'active')
            ->pluck('fleet_id')
            ->all();

        if (empty($fleetIds)) {
            return null;
        }

        $exact = FareRule::whereIn('fleet_id', $fleetIds)
            ->where('seat_type', $seatType)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();

        if ($exact) {
            return $exact;
        }

        return FareRule::whereIn('fleet_id', $fleetIds)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();
    }

    public function update(int $fareRuleId, array $payload): bool
    {
        return FareRule::where('fare_rule_id', $fareRuleId)->update($payload) > 0;
    }
}