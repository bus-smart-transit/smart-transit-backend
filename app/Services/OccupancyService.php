<?php

namespace App\Services;

use App\Models\Ticket;
use App\Repositories\StopRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use App\Support\TripCapacity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class OccupancyService
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private TripRepository $tripRepository,
        private StopRepository $stopRepository,
        private RequestedStopPlacementService $placementService,
    ) {}

    /**
     * Get real-time occupancy for a trip
     */
    public function getTripOccupancy(int $tripId): array
    {
        $trip = $this->tripRepository->findById($tripId);

        if (!$trip) {
            throw ValidationException::withMessages([
                'trip' => ['Trip not found.'],
            ]);
        }

        $fleet = $trip->fleetRoute->fleet;
        $seatedCapacity = $fleet->seated_capacity ?? 0;
        $standingCapacity = $fleet->standing_capacity ?? 0;
        $totalCapacity = $fleet->capacity ?? ($seatedCapacity + $standingCapacity);

        // Get boarded ticket counts by seat type
        $seatedBoarded = $this->ticketRepository->countBoardedByType($tripId, 'seated');
        $standingBoarded = $this->ticketRepository->countBoardedByType($tripId, 'standing');
        $totalBoarded = $seatedBoarded + $standingBoarded;

        // Check capacity status
        $seatedPercentage = $seatedCapacity > 0 ? ($seatedBoarded / $seatedCapacity) * 100 : 0;
        $standingPercentage = $standingCapacity > 0 ? ($standingBoarded / $standingCapacity) * 100 : 0;
        $totalPercentage = $totalCapacity > 0 ? ($totalBoarded / $totalCapacity) * 100 : 0;

        // Batch 27 Part 2, Task 5: capacity, occupied and "full" come from the ONE definition the sale block
        // uses (tickets sold). Boarded counts stay for the manifest and never decide a sale.
        $summary = TripCapacity::summary($trip, ['seated' => $seatedBoarded, 'standing' => $standingBoarded]);
        $capacityStatus = $this->getCapacityStatus($summary['utilization_percentage']);

        return [
            'capacity_summary' => $summary,
            'trip_id' => $tripId,
            'trip_status' => $trip->status,
            'fleet_id' => $fleet->fleet_id,
            'fleet_plate_number' => $fleet->plate_number,
            'capacity' => [
                'total' => $totalCapacity,
                'seated' => $seatedCapacity,
                'standing' => $standingCapacity,
            ],
            'boarded' => [
                'total' => $totalBoarded,
                'seated' => $seatedBoarded,
                'standing' => $standingBoarded,
            ],
            'utilization' => [
                'total_percentage' => $summary['utilization_percentage'],
                'seated_percentage' => $seatedCapacity > 0 ? round($summary['sold']['seated'] / $seatedCapacity * 100, 2) : 0,
                'standing_percentage' => $standingCapacity > 0 ? round($summary['sold']['standing'] / $standingCapacity * 100, 2) : 0,
            ],
            'available_seats' => $summary['remaining'],
            // Flat aliases kept for older frontend consumers.
            'total_capacity' => $totalCapacity,
            'seated_capacity' => $seatedCapacity,
            'standing_capacity' => $standingCapacity,
            'current_seated' => $seatedBoarded,
            'current_standing' => $standingBoarded,
            'capacity_status' => $capacityStatus,
            'is_full' => $summary['is_full'],
            'is_near_capacity' => $summary['utilization_percentage'] >= 80,
        ];
    }

    /**
     * Get occupancy breakdown by stop (passengers boarding/alighting at each stop)
     */
    /**
     * Get occupancy breakdown by stop.
     * Uses 2 aggregate SQL queries instead of 3×N per-stop queries.
     */
    public function getOccupancyByStop(int $tripId): array
    {
        $trip = $this->tripRepository->findById($tripId);

        if (!$trip) {
            throw ValidationException::withMessages([
                'trip' => ['Trip not found.'],
            ]);
        }

        $route = $trip->fleetRoute->route;
        $routeStops = $route->routeStops()->with('stop')->orderBy('stop_order')->get();

        // Aggregate boarding/alighting counts per stop in 2 queries instead
        // of 2×N per-stop queries.
        $counts = $this->ticketRepository->getBoardingAndAlightingCountsByStop($tripId);
        $boardingByStop = $counts['boarding'];
        $alightingByStop = $counts['alighting'];

        // Compute cumulative on-bus count in PHP — no additional DB calls
        $cumulativeOnBus = 0;
        $stopBreakdown = [];

        foreach ($routeStops as $routeStop) {
            $stopId   = $routeStop->stop_id;
            $boarding  = (int) ($boardingByStop[$stopId]  ?? 0);
            $alighting = (int) ($alightingByStop[$stopId] ?? 0);
            $cumulativeOnBus += $boarding - $alighting;

            $stopBreakdown[] = [
                'stop_id'                 => $stopId,
                'stop_name'               => $routeStop->stop->stop_name,
                'sequence_number'         => $routeStop->stop_order,
                'distance_from_origin_km' => $routeStop->distance_from_origin_km,
                'boarding_count'          => $boarding,
                'alighting_count'         => $alighting,
                'passengers_on_bus_after' => max(0, $cumulativeOnBus),
            ];
        }

        return [
            'trip_id'    => $tripId,
            'route_name' => $route->route_name,
            'stops'      => $stopBreakdown,
        ];
    }

    /**
     * Get passengers currently on bus with details
     */
    public function getCurrentPassengers(int $tripId): array
    {
        $trip = $this->tripRepository->findById($tripId);

        if (!$trip) {
            throw ValidationException::withMessages([
                'trip' => ['Trip not found.'],
            ]);
        }

        $passengers = $this->ticketRepository->getActivePassengersDetails($tripId);

        $payloadStopIds = [];
        foreach ($passengers as $ticket) {
            $item = $this->matchPaymentItemForTicket($ticket);
            $originId = isset($item['origin_stop_id']) ? (int) $item['origin_stop_id'] : null;
            $destinationId = isset($item['destination_stop_id']) ? (int) $item['destination_stop_id'] : null;

            if ($originId) {
                $payloadStopIds[] = $originId;
            }
            if ($destinationId) {
                $payloadStopIds[] = $destinationId;
            }
        }

        $payloadStopNames = $this->stopRepository->getNamesByIds($payloadStopIds);
        $legContext = $trip->fleetRoute ? $this->placementService->contextFor($trip) : null;

        return [
            'trip_id' => $tripId,
            'trip_status' => $trip->status,
            'total_count' => $passengers->count(),
            'passengers' => $passengers->map(function ($ticket) use ($payloadStopNames, $legContext) {
                $matchedItem = $this->matchPaymentItemForTicket($ticket);
                $payloadOriginId = isset($matchedItem['origin_stop_id']) ? (int) $matchedItem['origin_stop_id'] : null;
                $payloadDestinationId = isset($matchedItem['destination_stop_id']) ? (int) $matchedItem['destination_stop_id'] : null;

                $originStopName = $ticket->originStop?->stop_name
                    ?? ($payloadOriginId ? ($payloadStopNames[$payloadOriginId] ?? null) : null);
                $destinationStopName = $ticket->destinationStop?->stop_name
                    ?? ($payloadDestinationId ? ($payloadStopNames[$payloadDestinationId] ?? null) : null);
                // D5: a custom drop-off is shown as a requested stop.
                $requestedStop = $destinationStopName === null && $ticket->destination_lat !== null;
                if ($requestedStop) {
                    $destinationStopName = 'Requested stop: '.($ticket->destination_label ?: 'custom drop-off');
                }

                // Travel order of the drop-off along this leg: a stop is its leg sequence, a
                // requested stop sits between the stops around it, an unknown position is last.
                $dropOffOrder = 100001.0;
                if ($legContext) {
                    if ($requestedStop) {
                        $placement = $this->placementService->place($legContext, (float) $ticket->destination_lat, (float) $ticket->destination_lng);
                        $dropOffOrder = $this->placementService->sortKey($placement['after_stop_sequence'], $placement['position_known']);
                    } elseif ($ticket->destination_stop_id !== null || $payloadDestinationId) {
                        $dropOffOrder = (float) ($legContext['sequence_by_stop_id'][(int) ($ticket->destination_stop_id ?? $payloadDestinationId)] ?? 100001);
                    }
                }

                return [
                    'ticket_id' => $ticket->ticket_id,
                    'ticket_uuid' => $ticket->ticket_uuid,
                    'passenger_name' => $ticket->passenger->user->name ?? 'Guest',
                    'passenger_id' => $ticket->passenger_id,
                    'seat_type' => $ticket->seat_type,
                    'status' => $ticket->status,
                    'is_boarded' => $ticket->status === 'boarded',
                    'origin_stop' => $originStopName,
                    'destination_stop' => $destinationStopName,
                    'requested_stop' => $requestedStop,
                    'drop_off_order' => $dropOffOrder,
                    'boarded_at' => $ticket->boarded_at,
                ];
            })->sortBy('drop_off_order')->values()->toArray(),
        ];
    }

    private function matchPaymentItemForTicket(Ticket $ticket): array
    {
        $items = $ticket->payment?->items_payload ?? [];

        foreach ($items as $item) {
            $sameTrip = (int) ($item['trip_id'] ?? 0) === (int) $ticket->trip_id;
            $sameSeatType = ($item['seat_type'] ?? null) === $ticket->seat_type;

            if ($sameTrip && $sameSeatType) {
                return $item;
            }
        }

        return [];
    }

    /**
     * Check if trip can accept more passengers of a given type
     */
    public function canBoard(int $tripId, string $seatType): bool
    {
        $trip = $this->tripRepository->findById($tripId);

        if (!$trip) {
            return false;
        }

        $fleet = $trip->fleetRoute->fleet;
        $capacity = $seatType === 'seated' ? $fleet->seated_capacity : $fleet->standing_capacity;

        $boarded = $this->ticketRepository->countBoardedByType($tripId, $seatType);

        return $boarded < $capacity;
    }

    /**
     * Record passenger alighting (mark as alighted, not removed yet)
     */
    public function recordAlighting(int $ticketId, ?int $tripId = null): void
    {
        $ticket = $this->ticketRepository->findById($ticketId);

        // A ticket from another trip is reported as not found, like a missing one.
        if (! $ticket || ($tripId !== null && (int) $ticket->trip_id !== $tripId)) {
            throw (new ModelNotFoundException())->setModel(Ticket::class, $ticketId);
        }

        if ($ticket->status !== 'boarded') {
            throw ValidationException::withMessages([
                'ticket' => ['Only boarded passengers can alight.'],
            ]);
        }

        $this->ticketRepository->markAlighted($ticketId);
    }

    /**
     * Get cumulative passenger count after a specific stop
     */
    private function getCumulativePassengersAfterStop(int $tripId, int $stopId): int
    {
        // Count all passengers whose boarding is at or before this stop
        // and whose alighting is after this stop
        return $this->ticketRepository->countPassengersAfterStop($tripId, $stopId);
    }

    /**
     * Determine capacity status message
     */
    private function getCapacityStatus(float $percentage): string
    {
        if ($percentage >= 100) {
            return 'full';
        } elseif ($percentage >= 80) {
            return 'near_capacity';
        } elseif ($percentage >= 50) {
            return 'moderate';
        } elseif ($percentage > 0) {
            return 'low';
        }

        return 'empty';
    }
}
