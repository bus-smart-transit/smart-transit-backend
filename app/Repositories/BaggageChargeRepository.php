<?php

namespace App\Repositories;

use App\Models\BaggageCharge;
use App\Support\CompanyScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** 2026-11-25: every query on baggage_charges (BaggageChargeService owns the rules). */
class BaggageChargeRepository
{
    /** @param array<string, mixed> $data */
    public function create(array $data): BaggageCharge
    {
        return BaggageCharge::create($data);
    }

    public function find(int $chargeId): ?BaggageCharge
    {
        return BaggageCharge::find($chargeId);
    }

    public function markVoided(BaggageCharge $charge): BaggageCharge
    {
        $charge->forceFill(['voided_at' => now()])->save();

        return $charge;
    }

    /**
     * The charges still counted (not voided) on these tickets, by ticket id.
     *
     * @param  array<int, int>  $ticketIds
     * @return Collection<int, Collection<int, BaggageCharge>>
     */
    public function activeByTicket(array $ticketIds): Collection
    {
        if ($ticketIds === []) {
            return collect();
        }

        return BaggageCharge::whereIn('ticket_id', $ticketIds)
            ->whereNull('voided_at')
            ->orderBy('collected_at')
            ->get()
            ->groupBy('ticket_id');
    }

    /** @return array{count: int, cents: int} the charges still counted in a Chauffeur shift */
    public function totalsForShift(int $shiftLogId): array
    {
        $amounts = BaggageCharge::where('conductor_shift_log_id', $shiftLogId)->whereNull('voided_at')->pluck('amount');

        return ['count' => $amounts->count(), 'cents' => (int) $amounts->sum(fn ($a) => (int) round(((float) $a) * 100))];
    }

    /** The company that owns the trip's bus (null for a bus without one). */
    public function companyIdForTrip(int $tripId): ?int
    {
        $id = DB::table('trips')
            ->join('fleets_routes', 'fleets_routes.fleet_route_id', '=', 'trips.fleet_route_id')
            ->join('fleets', 'fleets.fleet_id', '=', 'fleets_routes.fleet_id')
            ->where('trips.trip_id', $tripId)
            ->value('fleets.company_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * The charges still counted that were collected in a range (Manila clock), on the owner's company's buses.
     *
     * @param  array{route_id: int|null, fleet_id: int|null, trip_id: int|null}  $filters
     * @return array{count: int, cents: int}
     */
    public function totalsForReport(?int $ownerCompanyUserId, CarbonInterface $start, CarbonInterface $end, array $filters): array
    {
        $query = DB::table('baggage_charges')
            ->join('trips', 'trips.trip_id', '=', 'baggage_charges.trip_id')
            ->join('fleets_routes', 'fleets_routes.fleet_route_id', '=', 'trips.fleet_route_id')
            ->join('fleets', 'fleets.fleet_id', '=', 'fleets_routes.fleet_id')
            ->whereNull('baggage_charges.voided_at')
            ->where('baggage_charges.collected_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('baggage_charges.collected_at', '<=', $end->format('Y-m-d H:i:s'));

        if ($ownerCompanyUserId !== null) {
            CompanyScope::whereFleetOfCompany($query, $ownerCompanyUserId);
        }
        if ($filters['route_id'] !== null) {
            $query->where('fleets_routes.route_id', $filters['route_id']);
        }
        if ($filters['fleet_id'] !== null) {
            $query->where('fleets.fleet_id', $filters['fleet_id']);
        }
        if ($filters['trip_id'] !== null) {
            $query->where('trips.trip_id', $filters['trip_id']);
        }

        $amounts = $query->pluck('baggage_charges.amount');

        return ['count' => $amounts->count(), 'cents' => (int) $amounts->sum(fn ($a) => (int) round(((float) $a) * 100))];
    }
}
