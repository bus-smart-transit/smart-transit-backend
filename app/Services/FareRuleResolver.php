<?php

namespace App\Services;

use App\Models\FareRule;
use App\Models\Fleet;
use App\Repositories\FareRuleRepository;
use App\Support\ServiceType;

/**
 * Batch 28 item 5b: the ONE place that decides which fare rule prices a trip. Lookup order:
 *
 *   1. a rule written for this fleet (override), for the trip's service type
 *   2. a general rule for (this route, the fleet's class, the service type, the seat type)
 *   3. a general rule for (any route, the fleet's class, the service type, the seat type)
 *   4. nothing: "Fare unavailable"
 *
 * It never falls back across class or service type: a fleet with no class and no override has no fare, and an
 * express trip is priced only where an express rule exists.
 */
class FareRuleResolver
{
    public function __construct(private FareRuleRepository $rules) {}

    public function resolve(Fleet|int|null $fleet, int $routeId, ?string $serviceType, string $seatType, ?string $on = null): ?FareRule
    {
        if (! $fleet instanceof Fleet) {
            $fleet = $fleet === null ? null : Fleet::find($fleet);
        }
        if (! $fleet) {
            return null;
        }

        $service = ServiceType::normalize($serviceType);

        $override = $this->rules->findFleetOverride((int) $fleet->fleet_id, $seatType, $service, $routeId, $on);
        if ($override) {
            return $override;
        }

        $class = $fleet->vehicle_class;
        if ($class === null || $class === '') {
            return null;
        }

        return $this->rules->findGeneral($routeId, $class, $service, $seatType, $on)
            ?? $this->rules->findGeneral(null, $class, $service, $seatType, $on);
    }

    public function isAvailable(Fleet|int|null $fleet, int $routeId, ?string $serviceType, string $seatType, ?string $on = null): bool
    {
        return $this->resolve($fleet, $routeId, $serviceType, $seatType, $on) !== null;
    }
}
