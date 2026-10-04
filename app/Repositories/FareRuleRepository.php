<?php
namespace App\Repositories;
use App\Models\FareRule;
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
     * Only rules in force on a date. A null effective_from / effective_to is open ended, so every rule that
     * existed before the columns keeps applying. The date is the Manila calendar date of the trip (default:
     * today). When two rules overlap, the one that started latest wins.
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

    /**
     * Step 1 of the fare key: a rule written for this fleet (an override). The service type must match exactly
     * (no regular-for-express fallback); a rule for this route beats a rule for any route. If the fleet has no
     * rule for the seat type, a rule of the same fleet and service type for another seat type is used (the
     * behaviour fleets had before the fare key, kept so existing standing sales still price).
     */
    public function findFleetOverride(int $fleetId, string $seatType, string $serviceType, int $routeId, ?string $on = null): ?FareRule
    {
        $base = fn () => FareRule::where('fleet_id', $fleetId)
            ->where('service_type', $serviceType)
            ->where(fn ($q) => $q->whereNull('route_id')->orWhere('route_id', $routeId));

        $exact = $this->inForce($base()->where('seat_type', $seatType), $on)
            ->reorder()
            ->orderByRaw('CASE WHEN route_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN effective_from IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('effective_from')
            ->orderByDesc('updated_at')
            ->first();
        if ($exact) {
            return $exact;
        }

        return $this->inForce($base(), $on)->first();
    }

    /** Steps 2 and 3: a general rule (no fleet) for a class, service type and seat type, for one route or for any route. */
    public function findGeneral(?int $routeId, string $vehicleClass, string $serviceType, string $seatType, ?string $on = null): ?FareRule
    {
        $query = FareRule::whereNull('fleet_id')
            ->where('vehicle_class', $vehicleClass)
            ->where('service_type', $serviceType)
            ->where('seat_type', $seatType);

        $routeId === null ? $query->whereNull('route_id') : $query->where('route_id', $routeId);

        return $this->inForce($query, $on)->first();
    }

    /**
     * True when another active rule with the same key is in force on any day of the range. The key is
     * (fleet or none, route or none, class, service type, seat type).
     *
     * @param  array{fleet_id?: int|null, route_id?: int|null, vehicle_class?: string|null, service_type?: string|null, seat_type: string}  $key
     */
    public function overlappingRuleExists(array $key, ?string $from, ?string $to, ?int $ignoreRuleId = null): bool
    {
        $query = FareRule::query()
            ->where('seat_type', $key['seat_type'])
            ->where('service_type', $key['service_type'] ?? 'regular')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $to ?? '9999-12-31'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from ?? '0001-01-01'));

        foreach (['fleet_id', 'route_id', 'vehicle_class'] as $column) {
            $value = $key[$column] ?? null;
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
        if ($ignoreRuleId !== null) {
            $query->where('fare_rule_id', '!=', $ignoreRuleId);
        }

        return $query->exists();
    }

    /**
     * Rules for the fare screen.
     *
     * @param  array{fleet_id?: int, fleet_ids?: array<int>, vehicle_class?: string, service_type?: string, seat_type?: string, route_id?: int, scope?: string, status?: string}  $filters
     * @return Collection<int, FareRule>
     */
    public function list(array $filters = []): Collection
    {
        $query = FareRule::query()->with(['fleet:fleet_id,plate_number,bus_company_name,body_no,vehicle_class']);

        foreach (['vehicle_class', 'service_type', 'seat_type', 'route_id', 'fleet_id', 'status'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['scope'])) {
            $filters['scope'] === 'general' ? $query->whereNull('fleet_id') : $query->whereNotNull('fleet_id');
        }
        if (isset($filters['fleet_ids'])) {
            // An operator sees general rules (read only) and the overrides of the fleets they own.
            $query->where(fn ($q) => $q->whereNull('fleet_id')->orWhereIn('fleet_id', $filters['fleet_ids']));
        }

        return $query->orderBy('vehicle_class')->orderBy('service_type')->orderBy('seat_type')->orderByDesc('effective_from')->get();
    }

    public function update(int $fareRuleId, array $payload): bool
    {
        return FareRule::where('fare_rule_id', $fareRuleId)->update($payload) > 0;
    }
}
