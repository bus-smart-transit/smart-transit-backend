<?php

namespace App\Services;

use App\Models\Trip;
use App\Repositories\RouteStopRepository;
use App\Support\BusLabel;
use App\Support\ServiceType;
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
     * The available trips for a Manila date, optionally narrowed to a journey (both stops, one
     * of them or neither), earliest departure first and paginated on the server. Today lists
     * only departures that have not left yet. When the chosen date has nothing, the first later
     * date inside the booking window that has departures comes back as `next_available_date`.
     * Each item carries the times, the seats left and the fare quoted by the same
     * FareRuleService the ticket is priced with. "Available" is `evaluate()`, the same
     * eligibility Book Now / Book Later use.
     *
     * A missing stop defaults per trip to the first (origin) or last (destination) stopping
     * point of the trip's direction, so an unfiltered list is the trips end to end.
     *
     * @param  array{origin_stop_id?: int|null, destination_stop_id?: int|null, date?: string|null, seat_type?: string|null}  $query
     * @return array{date: string, trips: array<int, array>, page: int, per_page: int, total: int, last_page: int, next_available_date: string|null, message: string|null}
     *
     * @throws ValidationException
     */
    public function available(array $query, int $page = 1, ?int $perPage = null): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $seatType = $query['seat_type'] ?? 'seated';
        $originId = isset($query['origin_stop_id']) ? (int) $query['origin_stop_id'] : null;
        $destinationId = isset($query['destination_stop_id']) ? (int) $query['destination_stop_id'] : null;
        $date = $this->assertDateInWindow($query['date'] ?? $now->toDateString(), $now, $timezone);

        $perPage = max(1, min($perPage ?? (int) config('booking.trips_per_page'), (int) config('booking.trips_max_per_page')));
        $found = $this->eligibleOnDate($date, $originId, $destinationId, $seatType, $now, $timezone);
        $total = count($found);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $fares = [];
        $items = array_map(function ($candidate) use ($seatType, &$fares) {
            $trip = $candidate['trip'];
            // The fare depends on the fleet's rule and the journey, not on the individual trip.
            $key = $trip->fleetRoute->fleet_id.'|'.$candidate['origin']['stop_id'].'|'.$candidate['destination']['stop_id'].'|'.ServiceType::normalize($trip->service_type);
            $fares[$key] ??= $this->fareFor($trip, $candidate['origin']['stop_id'], $candidate['destination']['stop_id'], $seatType);

            return $this->present($candidate) + ['fare' => $fares[$key]];
        }, array_slice($found, ($page - 1) * $perPage, $perPage));

        return [
            'date' => $date->toDateString(),
            'trips' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'next_available_date' => $total === 0 ? $this->nextAvailableDate($date, $originId, $destinationId, $seatType, $now, $timezone) : null,
            'message' => $total === 0 ? 'No departures match your journey on that date.' : null,
        ];
    }

    /**
     * Every eligible departure on one Manila date, earliest first. Today only those still to come.
     *
     * @return array<int, array>
     */
    private function eligibleOnDate(Carbon $date, ?int $originId, ?int $destinationId, string $seatType, Carbon $now, string $timezone): array
    {
        $from = $date->isSameDay($now) ? $now->copy() : $date->copy()->startOfDay();
        $trips = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->whereDate('trip_date', $date->toDateString())
            ->get();

        $found = [];
        foreach ($trips as $trip) {
            $candidate = $this->evaluate($trip, $originId, $destinationId, $seatType, $from, $timezone);
            if ($candidate) {
                $found[] = $candidate;
            }
        }
        usort($found, fn ($a, $b) => $a['boards_at']->getTimestamp() <=> $b['boards_at']->getTimestamp());

        return $found;
    }

    /** The first date after `$date`, inside the booking window, that has an eligible departure. */
    private function nextAvailableDate(Carbon $date, ?int $originId, ?int $destinationId, string $seatType, Carbon $now, string $timezone): ?string
    {
        $last = $now->copy()->startOfDay()->addDays((int) config('booking.max_advance_days'));
        $first = $date->copy()->startOfDay()->addDay();
        if ($first->gt($last)) {
            return null;
        }

        $trips = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->whereDate('trip_date', '>=', $first->toDateString())
            ->whereDate('trip_date', '<=', $last->toDateString())
            ->orderBy('trip_date')
            ->get();

        $next = null;
        foreach ($trips as $trip) {
            $tripDate = $trip->trip_date->toDateString();
            if ($next !== null && $tripDate >= $next) {
                continue;
            }
            if ($this->evaluate($trip, $originId, $destinationId, $seatType, $first, $timezone)) {
                $next = $tripDate;
            }
        }

        return $next;
    }

    private function fareFor(Trip $trip, int $originId, int $destinationId, string $seatType): ?float
    {
        if (ServiceType::isExpress($trip->service_type)) {
            return null; // "Fare unavailable": no regular-fare fallback and no surcharge
        }

        try {
            return (float) $this->fareRuleService->getQuote([
                'route_id' => (int) $trip->fleetRoute->route_id,
                'fleet_id' => (int) $trip->fleetRoute->fleet_id,
                'origin_stop_id' => $originId,
                'destination_stop_id' => $destinationId,
                'seat_type' => $seatType,
                'leg_direction' => $trip->leg_direction,
                'on' => $trip->trip_date?->toDateString(),
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
            // Book Now / Book Later pick only a bookable trip; an express trip has no fare yet.
            if ($candidate && ServiceType::isExpress($trip->service_type)) {
                continue;
            }
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

        $found = $this->eligibleOnDate($date, (int) $query['origin_stop_id'], (int) $query['destination_stop_id'], $seatType, $now, $timezone);

        return [
            'date' => $date->toDateString(),
            'departures' => array_map(fn ($candidate) => $this->present($candidate), $found),
            'message' => $found === [] ? 'No departures match your journey on that date.' : null,
        ];
    }

    /** @param array{trip: Trip, boards_at: Carbon, has_offset: bool, seats_left: int, arrives_at?: Carbon|null, duration_min?: int|null, origin?: array, destination?: array} $best */
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
            'origin' => $best['origin'] ?? null,
            'destination' => $best['destination'] ?? null,
            'route_name' => $best['trip']->fleetRoute->route?->route_name,
            'bus_label' => BusLabel::for($best['trip']->fleetRoute->fleet),
            'leg_direction' => $best['trip']->leg_direction === 'reverse' ? 'reverse' : 'outbound',
            'service_type' => ServiceType::normalize($best['trip']->service_type),
            // Stage 1: express has no fare rule yet, so it is listed but cannot be booked.
            'bookable' => ! ServiceType::isExpress($best['trip']->service_type),
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

    /**
     * @param  int|null  $originId  null = the first stopping point of the trip's direction
     * @param  int|null  $destinationId  null = the last stopping point of the trip's direction
     */
    private function evaluate(Trip $trip, ?int $originId, ?int $destinationId, string $seatType, Carbon $target, string $timezone): ?array
    {
        $fleetRoute = $trip->fleetRoute;
        $fleet = $fleetRoute?->fleet;
        if (! $fleetRoute || ! $fleet || $fleetRoute->status !== 'active') {
            return null;
        }

        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $stops = $this->routeStopRepository->getOrderedStopsForDirection((int) $fleetRoute->route_id, $direction)->values();
        $service = ServiceType::normalize($trip->service_type);
        // On an express trip a stop it does not serve is a pass-through for that trip.
        $isStopping = fn ($index) => ServiceType::isServed($service, $stops[$index]);
        $stoppingIndexes = $stops->keys()->filter($isStopping)->values();

        $originIndex = $originId === null ? $stoppingIndexes->first() : $stops->search(fn ($s) => (int) $s->stop_id === $originId);
        $destinationIndex = $destinationId === null ? $stoppingIndexes->last() : $stops->search(fn ($s) => (int) $s->stop_id === $destinationId);

        if ($originIndex === false || $originIndex === null || $destinationIndex === false || $destinationIndex === null
            || $originIndex >= $destinationIndex || ! $isStopping($originIndex) || ! $isStopping($destinationIndex)) {
            return null;
        }

        $time = $trip->departure_time ?: $fleetRoute->start_time;
        if (! $time) {
            return null;
        }

        $offset = ServiceType::offsetMinutes($service, $stops[$originIndex]);
        $destinationOffset = ServiceType::offsetMinutes($service, $stops[$destinationIndex]);
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

        return [
            'trip' => $trip, 'boards_at' => $boardsAt, 'has_offset' => $offset !== null, 'seats_left' => $seatsLeft, 'arrives_at' => $arrivesAt, 'duration_min' => $duration,
            'origin' => $this->stopSummary($stops[$originIndex]),
            'destination' => $this->stopSummary($stops[$destinationIndex]),
        ];
    }

    /** @return array{stop_id: int, stop_code: string|null, name: string|null} */
    private function stopSummary($routeStop): array
    {
        return [
            'stop_id' => (int) $routeStop->stop_id,
            'stop_code' => $routeStop->stop?->stop_code,
            'name' => $routeStop->stop?->stop_name,
        ];
    }
}