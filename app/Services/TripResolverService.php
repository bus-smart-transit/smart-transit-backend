<?php

namespace App\Services;

use App\Models\Trip;
use App\Repositories\RouteStopRepository;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * D3: there is no free trip picker. A passenger chooses where they board and
 * where they get off, and when; the trip is resolved here on the server.
 *
 * - Book Now = the next eligible departure from the current Manila time.
 * - Book Later = the earliest eligible departure on or after the chosen
 *   Manila date and time (future only, within the configured advance window).
 *
 * Eligible: the trip is still open for booking, its route serves the boarding
 * stop before the alighting stop in the trip's direction (both stopping
 * types), it leaves the boarding stop at or after the target time, and the
 * fleet still has a seat of the requested type. Availability is the existing
 * trip-level count (not per segment).
 */
class TripResolverService
{
    public function __construct(private RouteStopRepository $routeStopRepository) {}

    /**
     * @param  array{mode: string, origin_stop_id: int, destination_stop_id: int, date?: string|null, time?: string|null, seat_type?: string|null}  $query
     * @return array{trip: array|null, message: string|null, searched_from: string}
     *
     * @throws ValidationException
     */
    public function resolve(array $query): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $seatType = $query['seat_type'] ?? 'seated';
        $target = $this->targetTime($query, $now, $timezone);

        $windowEnd = $target->copy()->startOfDay()->addDays((int) config('booking.search_window_days'));
        $trips = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->whereDate('trip_date', '>=', $target->toDateString())
            ->whereDate('trip_date', '<=', $windowEnd->toDateString())
            ->get();

        $best = null;
        foreach ($trips as $trip) {
            $candidate = $this->evaluate($trip, (int) $query['origin_stop_id'], (int) $query['destination_stop_id'], $seatType, $target, $timezone);
            if ($candidate && ($best === null || $candidate['boards_at']->lt($best['boards_at']))) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return ['trip' => null, 'message' => 'No departures match your journey around that time.', 'searched_from' => $target->toIso8601String()];
        }

        return [
            'trip' => [
                'trip_id' => (int) $best['trip']->trip_id,
                'trip_date' => $best['trip']->trip_date->toDateString(),
                'boarding_time' => $best['boards_at']->format('H:i'),
                'boarding_at' => $best['boards_at']->toIso8601String(),
                'boarding_time_is_scheduled_offset' => $best['has_offset'],
                'route_name' => $best['trip']->fleetRoute->route?->route_name,
                'plate_number' => $best['trip']->fleetRoute->fleet?->plate_number,
                'leg_direction' => $best['trip']->leg_direction === 'reverse' ? 'reverse' : 'outbound',
                'seats_left' => $best['seats_left'],
            ],
            'message' => null,
            'searched_from' => $target->toIso8601String(),
        ];
    }

    /** @throws ValidationException */
    private function targetTime(array $query, Carbon $now, string $timezone): Carbon
    {
        if (($query['mode'] ?? 'now') === 'now') {
            return $now->copy();
        }

        if (empty($query['date']) || empty($query['time'])) {
            throw ValidationException::withMessages(['date' => ['Choose a date and time for Book Later.']]);
        }

        $target = Carbon::parse($query['date'].' '.$query['time'], $timezone);
        if ($target->lte($now)) {
            throw ValidationException::withMessages(['date' => ['Choose a date and time in the future (Manila time).']]);
        }

        $latest = $now->copy()->startOfDay()->addDays((int) config('booking.max_advance_days'))->endOfDay();
        if ($target->gt($latest)) {
            throw ValidationException::withMessages(['date' => ['You can book up to '.(int) config('booking.max_advance_days').' days ahead.']]);
        }

        return $target;
    }

    private function evaluate(Trip $trip, int $originId, int $destinationId, string $seatType, Carbon $target, string $timezone): ?array
    {
        $fleetRoute = $trip->fleetRoute;
        $fleet = $fleetRoute?->fleet;
        if (! $fleetRoute || ! $fleet || $fleetRoute->status !== 'active') {
            return null;
        }

        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $stops = $this->routeStopRepository->getOrderedStopsForDirection((int) $fleetRoute->route_id, $direction)->values();
        $originIndex = $stops->search(fn ($s) => (int) $s->stop_id === $originId);
        $destinationIndex = $stops->search(fn ($s) => (int) $s->stop_id === $destinationId);
        $stopping = config('timeline.stopping_types');

        if ($originIndex === false || $destinationIndex === false || $originIndex >= $destinationIndex
            || ! in_array($stops[$originIndex]->stop_type ?? 'stop', $stopping, true)
            || ! in_array($stops[$destinationIndex]->stop_type ?? 'stop', $stopping, true)) {
            return null;
        }

        $time = $trip->departure_time ?: $fleetRoute->start_time;
        if (! $time) {
            return null;
        }

        $offset = $stops[$originIndex]->scheduled_offset_min;
        $boardsAt = Carbon::parse($trip->trip_date->toDateString().' '.$time, $timezone)->addMinutes((int) ($offset ?? 0));
        if ($boardsAt->lt($target)) {
            return null;
        }

        $seatsLeft = $seatType === 'standing'
            ? (int) $fleet->standing_capacity - (int) $trip->current_standing_capacity
            : (int) $fleet->seated_capacity - (int) $trip->current_seated_capacity;
        if ($seatsLeft <= 0) {
            return null;
        }
        if (strtolower((string) $fleet->fleet_type) === 'private' && $seatType === 'standing') {
            return null;
        }

        return ['trip' => $trip, 'boards_at' => $boardsAt, 'has_offset' => $offset !== null, 'seats_left' => $seatsLeft];
    }
}