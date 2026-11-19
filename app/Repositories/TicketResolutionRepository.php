<?php

namespace App\Repositories;

use App\Models\TicketResolution;
use Illuminate\Support\Collection;

/**
 * Phase 4 (2026-11-19): reads of ticket_resolutions that the Operator's refund screens need (moved from
 * TicketResolutionController). TicketResolutionService still owns every rule about resolutions.
 */
class TicketResolutionRepository
{
    /**
     * The resolutions of these tickets, keyed by ticket id.
     *
     * @param  array<int, int>  $ticketIds
     * @return Collection<int, TicketResolution>
     */
    public function forTicketIds(array $ticketIds): Collection
    {
        return TicketResolution::whereIn('ticket_id', $ticketIds)->get()->keyBy('ticket_id');
    }

    /** One resolution with its ticket and trip (for the company check). */
    public function findWithTicketTrip(int $resolutionId): ?TicketResolution
    {
        return TicketResolution::with('ticket.trip')->find($resolutionId);
    }
}
