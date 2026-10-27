<?php

namespace App\Services;

use App\Models\DropOffConfirmation;
use App\Repositories\TicketRepository;
use App\Support\DropOffGroups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 2026-10-27 (smarttransit_prompt.md §7): the Chauffeur confirms in one action that a destination group got off.
 *
 *  - The group is the server's (DropOffGroups over the trip's current passengers); the client names it and sends the
 *    tickets it showed, minus anyone who stayed on (the exception path).
 *  - Every ticket sent must still be boarded on this trip and going to that destination; otherwise nothing changes
 *    and the Chauffeur is asked to refresh. Unrelated passengers are never marked.
 *  - Each ticket keeps its own record (status alighted, alighted_at); a DropOffConfirmation row keeps which tickets were
 *    confirmed together, by whom and when. Destinations and ticket history are never changed.
 *  - A drop-off does not acknowledge the stop, and a stop acknowledgement does not mean anyone got off.
 */
class DropOffGroupService
{
    public function __construct(
        private OccupancyService $occupancy,
        private TicketRepository $tickets,
    ) {}

    /**
     * @param  list<int>  $ticketIds  the tickets the Chauffeur saw in the group, minus those who stayed on
     * @return array{confirmed: int, destination: string, ticket_ids: list<int>}
     *
     * @throws ValidationException
     */
    public function confirm(int $tripId, int $conductorCompanyUserId, string $destinationKey, array $ticketIds): array
    {
        $ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));
        if ($ticketIds === []) {
            throw ValidationException::withMessages(['ticket_ids' => ['Choose at least one passenger who got off.']]);
        }

        return DB::transaction(function () use ($tripId, $conductorCompanyUserId, $destinationKey, $ticketIds) {
            $group = collect(DropOffGroups::fromPassengers($this->occupancy->getCurrentPassengers($tripId)['passengers']))
                ->firstWhere('key', $destinationKey);
            $eligible = $group['ticket_ids'] ?? [];

            $locked = $this->tickets->lockBoardedInTrip($tripId, $ticketIds);
            if (! $group || array_diff($ticketIds, $eligible) !== [] || $locked->count() !== count($ticketIds)) {
                throw ValidationException::withMessages(['ticket_ids' => ['The passenger list has changed. Refresh and confirm again.']]);
            }

            foreach ($ticketIds as $ticketId) {
                $this->tickets->markAlighted($ticketId);
            }

            $confirmation = DropOffConfirmation::create([
                'trip_id' => $tripId,
                'conductor_company_user_id' => $conductorCompanyUserId,
                'destination_key' => $destinationKey,
                'destination_label' => $group['destination'],
                'destination_stop_id' => $group['destination_stop_id'],
                'ticket_ids' => $ticketIds,
                'ticket_count' => count($ticketIds),
                'confirmed_at' => Carbon::now(),
            ]);
            AuditLogger::log('drop_off.group_confirmed', 'Trip', $tripId, ['confirmation_id' => $confirmation->id, 'destination_key' => $destinationKey, 'tickets' => count($ticketIds)]);

            return ['confirmed' => count($ticketIds), 'destination' => $group['destination'], 'ticket_ids' => $ticketIds];
        });
    }
}
