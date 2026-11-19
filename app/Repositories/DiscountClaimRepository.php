<?php

namespace App\Repositories;

use App\Models\DiscountClaim;
use App\Support\CompanyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Batch 75: every query about discounts claimed online. */
class DiscountClaimRepository
{
    public function create(array $attributes): DiscountClaim
    {
        return DiscountClaim::create($attributes);
    }

    public function findForTicket(int $ticketId): ?DiscountClaim
    {
        return DiscountClaim::where('ticket_id', $ticketId)->first();
    }

    public function findForUpdate(int $id): ?DiscountClaim
    {
        return DiscountClaim::whereKey($id)->lockForUpdate()->first();
    }

    public function findByGatewayRefundId(string $refundId): ?DiscountClaim
    {
        return DiscountClaim::where('gateway_refund_id', $refundId)->first();
    }

    public function find(int $id): ?DiscountClaim
    {
        return DiscountClaim::find($id);
    }

    /** @return Collection<int, DiscountClaim> keyed by ticket_id */
    public function forTickets(array $ticketIds): Collection
    {
        if ($ticketIds === []) {
            return collect();
        }

        return DiscountClaim::whereIn('ticket_id', $ticketIds)->get()->keyBy('ticket_id');
    }

    /** Claims of a trip still waiting for the Chauffeur become lapsed (the trip ended); returns how many. */
    public function lapseOpenForTrip(int $tripId): int
    {
        return DiscountClaim::where('status', DiscountClaim::CLAIMED)
            ->whereHas('ticket', fn ($q) => $q->where('trip_id', $tripId))
            ->update(['status' => DiscountClaim::LAPSED, 'updated_at' => now()]);
    }

    /**
     * Batch 76: claims on tickets that were missed lapse (the passenger never boarded, so nobody checks an ID).
     *
     * @param  array<int, int>  $ticketIds
     */
    public function lapseOpenForTickets(array $ticketIds): int
    {
        return $ticketIds === [] ? 0 : DiscountClaim::where('status', DiscountClaim::CLAIMED)
            ->whereIn('ticket_id', $ticketIds)
            ->update(['status' => DiscountClaim::LAPSED, 'updated_at' => now()]);
    }

    /** @return Collection<int, DiscountClaim> approved refunds whose waiting time (Maya) is over and not sent yet */
    public function deferredDue(): Collection
    {
        return DiscountClaim::where('refund_status', DiscountClaim::REFUND_PENDING)
            ->whereNull('gateway_refund_id')
            ->whereNotNull('not_before')
            ->where('not_before', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
            ->get();
    }

    /**
     * Batch 81 (user, 2026-11-19): the Finance > Discounts list. Online discount claims on the company's trips dated in
     * the range (every company for the Admin, owner null), newest first, with the trip and route they belong to.
     *
     * @return Collection<int, object>
     */
    public function forCompanyBetween(?int $operatorCompanyUserId, string $from, string $to, int $limit = 500): Collection
    {
        $query = DB::table('discount_claims')
            ->join('tickets', 'tickets.ticket_id', '=', 'discount_claims.ticket_id')
            ->join('trips', 'trips.trip_id', '=', 'tickets.trip_id')
            ->leftJoin('fleets_routes', 'fleets_routes.fleet_route_id', '=', 'trips.fleet_route_id')
            ->leftJoin('routes', 'routes.route_id', '=', 'fleets_routes.route_id')
            ->whereDate('trips.trip_date', '>=', $from)
            ->whereDate('trips.trip_date', '<=', $to)
            ->select([
                'discount_claims.id', 'discount_claims.ticket_id', 'discount_claims.discount_label', 'discount_claims.discount_rate',
                'discount_claims.status', 'discount_claims.fare', 'discount_claims.refund_amount', 'discount_claims.refund_status',
                'discount_claims.reviewed_at', 'discount_claims.created_at', 'tickets.ticket_uuid', 'trips.trip_id', 'trips.trip_date',
                'trips.departure_time', 'routes.route_name',
            ])
            ->orderByDesc('discount_claims.id')
            ->limit($limit);
        if ($operatorCompanyUserId !== null) {
            CompanyScope::whereMadeByCompany($query, 'trips.company_user_id', $operatorCompanyUserId);
        }

        return $query->get();
    }
}
