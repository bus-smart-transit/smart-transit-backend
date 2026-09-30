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
        $stops = $this->routeStopRepository->getOrderedStopsForDirection($routeId, $trip->leg_direction);
        $acknowledgedStopId = (int) ($trip->last_acknowledged_stop_id ?? 0);

        $acknowledgedOrder = null;
        if ($acknowledgedStopId > 0) {
            $matchedRouteStop = $stops->firstWhere('stop_id', $acknowledgedStopId);
            if ($matchedRouteStop) {
                $acknowledgedOrder = (int) $matchedRouteStop->stop_order;
            }
        }

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
                'route_stop_id' => $routeStop->id,
                'stop_id' => $routeStop->stop_id,
                'stop_name' => $routeStop->stop->stop_name,
                'municipality' => $routeStop->stop->municipality,
                'sequence_number' => $routeStop->stop_order,
                'distance_from_origin_km' => $routeStop->distance_from_origin_km,
                'latitude' => $routeStop->stop->latitude !== null ? (float) $routeStop->stop->latitude : null,
                'longitude' => $routeStop->stop->longitude !== null ? (float) $routeStop->stop->longitude : null,
                'passengers_by_destination' => $passengersByDestination
            ];
        })->map(function ($row) use ($acknowledgedOrder, $acknowledgedStopId) {
            $isAcknowledged = false;
            if ($acknowledgedOrder !== null) {
                $isAcknowledged = (int) $row['sequence_number'] <= $acknowledgedOrder;
            } elseif ($acknowledgedStopId > 0) {
                $isAcknowledged = (int) $row['stop_id'] === $acknowledgedStopId;
            }

            $row['is_acknowledged'] = $isAcknowledged;
            return $row;
        })->toArray();

        return [
            'trip_id' => $trip->trip_id,
            'trip_status' => $trip->status,
            'route_name' => $trip->fleetRoute->route->route_name,
            'leg_direction' => $trip->leg_direction ?? 'outbound',
            'last_acknowledged_stop_id' => $trip->last_acknowledged_stop_id,
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
            'route_stop_id' => $routeStop->id,
            'stop_id' => $routeStop->stop_id,
            'stop_name' => $routeStop->stop->stop_name,
            'sequence_number' => $routeStop->stop_order,
            'distance_from_origin_km' => $routeStop->distance_from_origin_km,
            'latitude' => $routeStop->stop->latitude !== null ? (float) $routeStop->stop->latitude : null,
            'longitude' => $routeStop->stop->longitude !== null ? (float) $routeStop->stop->longitude : null,
            'is_acknowledged' => (int) $stopId === (int) ($trip->last_acknowledged_stop_id ?? 0),
            'passengers_by_destination' => $passengersByDestination
        ];
    }

    /**
     * Mark a stop as acknowledged/reached by the driver. Idempotent and
     * monotonic: repeating a stop, or acknowledging one already behind the
     * last acknowledged stop, changes nothing. Only a live trip on this
     * route's stops can be acknowledged.
     */
    public function acknowledgeStop(int $driverId, int $stopId): bool
    {
        $trip = $this->tripRepository->findCurrentByDriver($driverId);

        if (!$trip || !$trip->fleetRoute || !in_array($trip->status, ['departed', 'in-progress'], true)) {
            return false;
        }

        $stops = $this->routeStopRepository->getOrderedStopsForDirection($trip->fleetRoute->route_id, $trip->leg_direction);
        $target = $stops->firstWhere('stop_id', $stopId);
        if (!$target) {
            return false;
        }

        $lastId = (int) ($trip->last_acknowledged_stop_id ?? 0);
        $last = $lastId > 0 ? $stops->firstWhere('stop_id', $lastId) : null;
        if ($last && (int) $target->stop_order <= (int) $last->stop_order) {
            return true;
        }

        return $this->tripRepository->updateLastAcknowledgedStop($trip->trip_id, $stopId);
    }

    private function resolvePassengerDisplayName(object $ticket): string
    {
        $name = $ticket->passenger?->name
            ?? $ticket->passenger?->user?->username
            ?? $ticket->passenger?->user?->email;

        return is_string($name) && $name !== '' ? $name : 'Passenger';
    }
}
