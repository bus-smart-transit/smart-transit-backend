<?php

namespace App\Services;

use App\Repositories\TripRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\TicketRepository;

class DriverNavigationService
{
    private TripRepository $tripRepository;
    private RouteStopRepository $routeStopRepository;
    private TicketRepository $ticketRepository;

    public function __construct(
        TripRepository $tripRepository,
        RouteStopRepository $routeStopRepository,
        TicketRepository $ticketRepository
    ) {
        $this->tripRepository = $tripRepository;
        $this->routeStopRepository = $routeStopRepository;
        $this->ticketRepository = $ticketRepository;
    }

    /**
     * Get current trip with all stops and passengers grouped by destination
     */
    public function getCurrentTripWithStops(int $driverId): ?array
    {
        $trip = $this->tripRepository->findCurrentByDriver($driverId);
        
        if (!$trip) {
            return null;
        }

        if (!$trip->fleetRoute || !$trip->fleetRoute->route_id) {
            return null;
        }

        $routeId = $trip->fleetRoute->route_id;
        $stops = $this->routeStopRepository->getOrderedStops($routeId);

        $stopsData = $stops->map(function ($routeStop) use ($trip) {
            $passengers = $this->ticketRepository->getPassengersAlightingAtStop(
                $trip->trip_id,
                $routeStop->stop_id
            );

            // Group passengers by destination stop
            $passengersByDestination = $passengers->groupBy('destination_stop_id')
                ->map(function ($group) {
                    return [
                        'destination_stop_name' => $group->first()->destinationStop->stop_name ?? 'Unknown',
                        'passengers' => $group->map(fn($ticket) => [
                            'passenger_name' => $this->resolvePassengerDisplayName($ticket),
                            'ticket_id' => $ticket->ticket_id,
                            'ticket_uuid' => $ticket->ticket_uuid,
                            'seat_type' => $ticket->seat_type
                        ])->values()->toArray()
                    ];
                })->values();

            return [
                'stop_id' => $routeStop->stop_id,
                'stop_name' => $routeStop->stop->stop_name,
                'sequence_number' => $routeStop->stop_order,
                'distance_from_origin_km' => $routeStop->distance_from_origin,
                'passengers_by_destination' => $passengersByDestination
            ];
        })->toArray();

        return [
            'trip_id' => $trip->trip_id,
            'trip_status' => $trip->status,
            'route_name' => $trip->fleetRoute->route->route_name,
            'stops' => $stopsData
        ];
    }

    /**
     * Get details for a specific stop including all passengers alighting there
     */
    public function getStopDetails(int $driverId, int $stopId): ?array
    {
        $trip = $this->tripRepository->findCurrentByDriver($driverId);
        
        if (!$trip) {
            return null;
        }

        if (!$trip->fleetRoute || !$trip->fleetRoute->route_id) {
            return null;
        }

        $passengers = $this->ticketRepository->getPassengersAlightingAtStop(
            $trip->trip_id,
            $stopId
        );

        // Get stop information
        $routeStop = $this->routeStopRepository->findByRouteAndStop(
            $trip->fleetRoute->route_id,
            $stopId
        );

        if (!$routeStop) {
            return null;
        }

        // Group passengers by destination
        $passengersByDestination = $passengers->groupBy('destination_stop_id')
            ->map(function ($group) {
                return [
                    'destination_stop_name' => $group->first()->destinationStop->stop_name ?? 'Unknown',
                    'passengers' => $group->map(fn($ticket) => [
                        'passenger_name' => $this->resolvePassengerDisplayName($ticket),
                        'ticket_id' => $ticket->ticket_id,
                        'ticket_uuid' => $ticket->ticket_uuid,
                        'seat_type' => $ticket->seat_type
                    ])->values()->toArray()
                ];
            })->values();

        return [
            'stop_id' => $routeStop->stop_id,
            'stop_name' => $routeStop->stop->stop_name,
            'sequence_number' => $routeStop->stop_order,
            'distance_from_origin_km' => $routeStop->distance_from_origin,
            'passengers_by_destination' => $passengersByDestination
        ];
    }

    /**
     * Mark a stop as acknowledged/reached by driver
     */
    public function acknowledgeStop(int $driverId, int $stopId): bool
    {
        $trip = $this->tripRepository->findCurrentByDriver($driverId);
        
        if (!$trip) {
            return false;
        }

        // Update trip with last acknowledged stop
        return $this->tripRepository->updateLastAcknowledgedStop(
            $trip->trip_id,
            $stopId
        );
    }

    private function resolvePassengerDisplayName(object $ticket): string
    {
        $name = $ticket->passenger?->name
            ?? $ticket->passenger?->user?->username
            ?? $ticket->passenger?->user?->email;

        return is_string($name) && $name !== '' ? $name : 'Passenger';
    }
}
