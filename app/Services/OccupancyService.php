<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\Trip;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use Illuminate\Validation\ValidationException;

class OccupancyService
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private TripRepository $tripRepository,
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

        $capacityStatus = $this->getCapacityStatus($totalPercentage);

        return [
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
                'total_percentage' => round($totalPercentage, 2),
                'seated_percentage' => round($seatedPercentage, 2),
                'standing_percentage' => round($standingPercentage, 2),
            ],
            'available_seats' => [
                'total' => max(0, $totalCapacity - $totalBoarded),
                'seated' => max(0, $seatedCapacity - $seatedBoarded),
                'standing' => max(0, $standingCapacity - $standingBoarded),
            ],
            'capacity_status' => $capacityStatus,
            'is_full' => $totalBoarded >= $totalCapacity,
            'is_near_capacity' => $totalPercentage >= 80,
        ];
    }

    /**
     * Get occupancy breakdown by stop (passengers boarding/alighting at each stop)
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
        $routeStops = $route->routeStops()->orderBy('stop_order')->get();

        $stopBreakdown = [];

        foreach ($routeStops as $routeStop) {
            $stopId = $routeStop->stop_id;

            // Get passengers boarding at this stop
            $boarding = $this->ticketRepository->countBoardingAtStop($tripId, $stopId);

            // Get passengers alighting at this stop
            $alighting = $this->ticketRepository->countAlightingAtStop($tripId, $stopId);

            // Cumulative passengers on bus after this stop
            $onBus = $this->getCumulativePassengersAfterStop($tripId, $stopId);

            $stopBreakdown[] = [
                'stop_id' => $stopId,
                'stop_name' => $routeStop->stop->stop_name,
                'sequence_number' => $routeStop->stop_order,
                'distance_from_origin_km' => $routeStop->distance_from_origin_km,
                'boarding_count' => $boarding,
                'alighting_count' => $alighting,
                'passengers_on_bus_after' => $onBus,
            ];
        }

        return [
            'trip_id' => $tripId,
            'route_name' => $route->route_name,
            'stops' => $stopBreakdown,
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

        $passengers = $this->ticketRepository->getBoardedPassengersDetails($tripId);

        return [
            'trip_id' => $tripId,
            'trip_status' => $trip->status,
            'total_count' => $passengers->count(),
            'passengers' => $passengers->map(fn($ticket) => [
                'ticket_id' => $ticket->ticket_id,
                'ticket_uuid' => $ticket->ticket_uuid,
                'passenger_name' => $ticket->passenger->user->name ?? 'Guest',
                'passenger_id' => $ticket->passenger_id,
                'seat_type' => $ticket->seat_type,
                'origin_stop' => $ticket->originStop?->stop_name,
                'destination_stop' => $ticket->destinationStop?->stop_name,
                'boarded_at' => $ticket->boarded_at,
            ])->values()->toArray(),
        ];
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
    public function recordAlighting(int $ticketId): void
    {
        $ticket = Ticket::findOrFail($ticketId);

        if ($ticket->status !== 'boarded') {
            throw ValidationException::withMessages([
                'ticket' => ['Only boarded passengers can alight.'],
            ]);
        }

        $ticket->update([
            'status' => 'alighted',
            'alighted_at' => now(),
        ]);
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
