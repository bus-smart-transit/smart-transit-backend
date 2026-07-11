<?php
namespace App\Repositories;
use App\Models\FareRule;
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
        return FareRule::where('fleet_id', $fleetId)
            ->where('seat_type', $seatType)
            ->where('status', 'active')
            ->first();
    }

    public function update(int $fareRuleId, array $payload): bool
    {
        return FareRule::where('fare_rule_id', $fareRuleId)->update($payload) > 0;
    }
}