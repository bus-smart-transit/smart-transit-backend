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
    public function __construct(
        private RouteStopRepository $routeStopRepository,
        private FareRuleService $fareRuleService,
    ) {}

    /**
     * The trip list behind the search results: the earliest eligible departures from the
     * chosen Manila date on (today: from now), looking ahead up to the search window, so the
     * list fills up even when the chosen day runs few buses. With no limit it returns every
     * match in the window (the "all available trips" page). Each item carries the times, the
     * seats left and the fare quoted by the same FareRuleService the ticket is priced with.
     *
     * @param  array{origin_stop_id: int, destination_stop_id: int, date: string, seat_type?: string|null}  $query
     * @return array{date: string, trips: array<int, array>, total: int, message: string|null}
     *
     * @throws ValidationException
     */
    public function upcoming(array $query, ?int $limit = null): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $seatType = $query['seat_type'] ?? 'seated';
        $date = $this->assertDateInWindow($query['date'], $now, $timezone);

        $from = $date->isSameDay($now) ? $now->copy() : $date->copy()->startOfDay();
        $until = $date->copy()->startOfDay()->addDays((int) config('booking.search_window_days'));
        $trips = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->whereDate('trip_date', '>=', $date->toDateString())
            ->whereDate('trip_date', '<=', $until->toDateString())
            ->get();

        $found = [];
        foreach ($trips as $trip) {
            $candidate = $this->evaluate($trip, (int) $query['origin_stop_id'], (int) $query['destination_stop_id'], $seatType, $from, $timezone);
            if ($candidate) {
                $found[] = $candidate;
            }
        }
        usort($found, fn ($a, $b) => $a['boards_at']->getTimestamp() <=> $b['boards_at']->getTimestamp());

        $total = count($found);
        if ($limit !== null) {
            $found = array_slice($found, 0, max(1, $limit));
        }

        $fares = [];
        $items = array_map(function ($candidate) use ($query, $seatType, &$fares) {
            $fleetId = (int) $candidate['trip']->fleetRoute->fleet_id;
            // The fare depends on the fleet's rule and the journey, not on the individual trip.
            $fares[$fleetId] ??= $this->fareFor($candidate['trip'], (int) $query['origin_stop_id'], (int) $query['destination_stop_id'], $seatType);

            return $this->present($candidate) + ['fare' => $fares[$fleetId]];
        }, $found);

        return [
            'date' => $date->toDateString(),
            'trips' => $items,
            'total' => $total,
            'message' => $total === 0 ? 'No departures match your journey in the next days.' : null,
        ];
    }

    private function fareFor(Trip $trip, int $originId, int $destinationId, string $seatType): ?float
    {
        try {
            return (float) $this->fareRuleService->getQuote([
                'route_id' => (int) $trip->fleetRoute->route_id,
                'fleet_id' => (int) $trip->fleetRoute->fleet_id,
                'origin_stop_id' => $originId,
                'destination_stop_id' => $destinationId,
                'seat_type' => $seatType,
            ]);
        } catch (\Throwable) {
            return null; // shown as "fare unavailable"; never guessed
        }
    }

    /** @throws ValidationException */
    private function assertDateInWindow(string $value, Carbon $now, string $timezone): Carbon
    {
        $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        if ($date->lt($now->copy()->startOfDay())) {
            throw ValidationException::withMessages(['date' => ['That date has already passed (Manila time).']]);
        }
        $maxDays = (int) config('booking.max_advance_days');
        if ($maxDays > 0 && $date->gt($now->copy()->startOfDay()->addDays($maxDays))) {
            throw ValidationException::withMessages(['date' => ["You can book up to $maxDays days ahead."]]);
        }

        return $date;
    }

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
            'trip' => $this->present($best),
            'message' => null,
            'searched_from' => $target->toIso8601String(),
        ];
    }

    /**
     * Book Later: every eligible departure on one Manila date, earliest first, for the
     * passenger to choose from. Today lists only departures that have not yet left.
     *
     * @param  array{origin_stop_id: int, destination_stop_id: int, date: string, seat_type?: string|null}  $query
     * @return array{date: string, departures: array<int, array>, message: string|null}
     *
     * @throws ValidationException
     */
    public function departures(array $query): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $seatType = $query['seat_type'] ?? 'seated';
        $date = $this->assertDateInWindow($query['date'], $now, $timezone);

        $from = $date->isSameDay($now) ? $now->copy() : $date->copy()->startOfDay();
        $trips = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->whereDate('trip_date', $date->toDateString())
            ->get();

        $found = [];
        foreach ($trips as $trip) {
            $candidate = $this->evaluate($trip, (int) $query['origin_stop_id'], (int) $query['destination_stop_id'], $seatType, $from, $timezone);
            if ($candidate) {
                $found[] = $candidate;
            }
        }
        usort($found, fn ($a, $b) => $a['boards_at']->getTimestamp() <=> $b['boards_at']->getTimestamp());

        return [
            'date' => $date->toDateString(),
            'departures' => array_map(fn ($candidate) => $this->present($candidate), $found),
            'message' => $found === [] ? 'No departures match your journey on that date.' : null,
        ];
    }

    /** @param array{trip: Trip, boards_at: Carbon, has_offset: bool, seats_left: int, arrives_at?: Carbon|null, duration_min?: int|null} $best */
    private function present(array $best): array
    {
        return [
            'trip_id' => (int) $best['trip']->trip_id,
            'trip_date' => $best['trip']->trip_date->toDateString(),
            'boarding_time' => $best['boards_at']->format('H:i'),
            'boarding_at' => $best['boards_at']->toIso8601String(),
            'boarding_time_is_scheduled_offset' => $best['has_offset'],
            'arrival_time' => ($best['arrives_at'] ?? null)?->format('H:i'),
            'duration_minutes' => $best['duration_min'] ?? null,
            'route_name' => $best['trip']->fleetRoute->route?->route_name,
            'plate_number' => $best['trip']->fleetRoute->fleet?->plate_number,
            'leg_direction' => $best['trip']->leg_direction === 'reverse' ? 'reverse' : 'outbound',
            'seats_left' => $best['seats_left'],
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
        $destinationOffset = $stops[$destinationIndex]->scheduled_offset_min;
        $start = Carbon::parse($trip->trip_date->toDateString().' '.$time, $timezone);
        $boardsAt = $start->copy()->addMinutes((int) ($offset ?? 0));
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

        // Arrival at the alighting stop and the journey time, only when both stops have a scheduled offset.
        $hasTimes = $offset !== null && $destinationOffset !== null;
        $arrivesAt = $hasTimes ? $start->copy()->addMinutes((int) $destinationOffset) : null;
        $duration = $hasTimes ? max(0, (int) round((float) $destinationOffset - (float) $offset)) : null;

        return ['trip' => $trip, 'boards_at' => $boardsAt, 'has_offset' => $offset !== null, 'seats_left' => $seatsLeft, 'arrives_at' => $arrivesAt, 'duration_min' => $duration];
    }
}