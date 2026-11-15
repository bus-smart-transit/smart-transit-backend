<?php

namespace App\Repositories;

use App\Models\DiscountClaim;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /** @return Collection<int, DiscountClaim> approved refunds whose waiting time (Maya) is over and not sent yet */
    public function deferredDue(): Collection
    {
        return DiscountClaim::where('refund_status', DiscountClaim::REFUND_PENDING)
            ->whereNull('gateway_refund_id')
            ->whereNotNull('not_before')
            ->where('not_before', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
            ->get();
    }
}
