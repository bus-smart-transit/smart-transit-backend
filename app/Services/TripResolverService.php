<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\Trip;
use App\Repositories\RouteStopRepository;
use App\Support\BookingWindow;
use App\Support\BusLabel;
use App\Support\ServiceType;
use App\Support\TripRunInfo;
use App\Support\VehicleClass;
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
        private FareRuleResolver $fareRuleResolver,
        private RouteDurationFactorService $durationFactors,
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

            return $this->present($candidate, $seatType) + ['fare' => $fares[$key]];
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
        $last = BookingWindow::lastDate($now) ?? $now->copy()->startOfDay();
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

    /** True when a fare rule prices this trip (its bus, route, service type and seat type). Express is bookable only where an express rule exists. */
    private function hasFare(Trip $trip, string $seatType): bool
    {
        return $this->fareRuleResolver->isAvailable(
            $trip->fleetRoute?->fleet,
            (int) $trip->fleetRoute?->route_id,
            $trip->service_type,
            $seatType,
            $trip->trip_date?->toDateString(),
        );
    }

    private function fareFor(Trip $trip, int $originId, int $destinationId, string $seatType): ?float
    {
        try {
            return (float) $this->fareRuleService->getQuote([
                'route_id' => (int) $trip->fleetRoute->route_id,
                'fleet_id' => (int) $trip->fleetRoute->fleet_id,
                'service_type' => $trip->service_type,
                'origin_stop_id' => $originId,
                'destination_stop_id' => $destinationId,
                'seat_type' => $seatType,
                'leg_direction' => $trip->leg_direction,
                'on' => $trip->trip_date?->toDateString(),
            ]);
        } catch (\Throwable) {
            return null; // shown as "Fare unavailable"; never guessed
        }
    }

    /** @throws ValidationException */
    private function assertDateInWindow(string $value, Carbon $now, string $timezone): Carbon
    {
        $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        if ($date->lt($now->copy()->startOfDay())) {
            throw ValidationException::withMessages(['date' => ['That date has already passed.']]);
        }
        $last = BookingWindow::lastDate($now);
        if ($last !== null && $date->gt($last)) {
            throw ValidationException::withMessages(['date' => [BookingWindow::aheadMessage($now)]]);
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
            // Book Now / Book Later pick only a bookable trip: one that has a fare rule (an express trip needs an express rule).
            if ($candidate && ! $this->hasFare($trip, $seatType)) {
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
            'trip' => $this->present($best, $seatType),
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
            'departures' => array_map(fn ($candidate) => $this->present($candidate, $seatType), $found),
            'message' => $found === [] ? 'No departures match your journey on that date.' : null,
        ];
    }

    /** @param array{trip: Trip, boards_at: Carbon, has_offset: bool, seats_left: int, arrives_at?: Carbon|null, duration_min?: int|null, origin?: array, destination?: array} $best */
    private function present(array $best, string $seatType = 'seated'): array
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
            // Batch 28 Stage 3, item 8: how the bus runs (a block of several legs is a "Round-trip run"; deliberately not worded
            // like a passenger's roundtrip booking) and, on an express, which stops it serves ("Non-stop" when only terminals).
            'run_pattern' => TripRunInfo::runPattern(
                $best['trip']->trip_type,
                $best['trip']->shift_block_id ? (int) $best['trip']->shift_block_id : null,
                $best['trip']->shift_block_id ? Trip::where('shift_block_id', $best['trip']->shift_block_id)->count() : null,
            ),
            'service' => $best['service_info'] ?? null,
            // Bookable only where a fare rule prices this trip (an express trip needs an express rule); otherwise it is
            // listed with "Fare unavailable" and cannot be booked.
            'bookable' => $this->hasFare($best['trip'], $seatType),
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
            throw ValidationException::withMessages(['date' => ['Choose a date and time in the future.']]);
        }

        $latest = BookingWindow::lastDate($now)?->endOfDay();
        if ($latest !== null && $target->gt($latest)) {
            throw ValidationException::withMessages(['date' => [BookingWindow::aheadMessage($now)]]);
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

        // Batch 28 Stage 3, item 7: the leg's peak-hour factor scales the offsets (1.0 when no window matches).
        $factor = $this->durationFactors->factorForTrip($trip);
        $offset = $this->durationFactors->scale(ServiceType::offsetMinutes($service, $stops[$originIndex]), $factor);
        $destinationOffset = $this->durationFactors->scale(ServiceType::offsetMinutes($service, $stops[$destinationIndex]), $factor);
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
            'service_info' => TripRunInfo::service($service, $stops),
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

    /**
     * Batch 28 Stage 3, item 6: the later departures a ticket of an operator-cancelled trip can be rebooked onto.
     * Same route, direction, service type and vehicle class; the ticket's own boarding and alighting stops (a ticket
     * bought at a snapped boarding point or a custom drop-off has no stop to rebook between: refund only); room for
     * its seat type; leaving after the cancelled trip left that stop. Rebooking costs nothing extra, so no fare rule
     * is required of the new trip. Earliest first, at most $limit.
     *
     * Capacity: a rebooked ticket takes one place on the new trip's counters (tickets sold), exactly like any sale;
     * the cancelled trip's counters are left as they were (the trip no longer runs).
     *
     * @return array<int, array>
     */
    public function rebookOptions(Ticket $ticket, int $limit): array
    {
        $original = $ticket->trip;
        if (! $original || ! $ticket->origin_stop_id || ! $ticket->destination_stop_id) {
            return [];
        }
        $original->loadMissing('fleetRoute.fleet');

        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $routeId = (int) $original->fleetRoute->route_id;
        $direction = $original->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $service = ServiceType::normalize($original->service_type);
        $class = VehicleClass::normalize($original->fleetRoute->fleet?->vehicle_class);
        $seatType = (string) ($ticket->seat_type ?: 'seated');
        $originId = (int) $ticket->origin_stop_id;
        $destinationId = (int) $ticket->destination_stop_id;

        // When the cancelled trip was due at the boarding stop: later than that, and not in the past.
        $stops = $this->routeStopRepository->getOrderedStopsForDirection($routeId, $direction)->values();
        $originStop = $stops->first(fn ($s) => (int) $s->stop_id === $originId);
        $time = $original->departure_time ?: $original->fleetRoute->start_time;
        if (! $originStop || ! $time) {
            return [];
        }
        $originalBoardsAt = Carbon::parse($original->trip_date->toDateString().' '.$time, $timezone)
            ->addMinutes((int) (ServiceType::offsetMinutes($service, $originStop) ?? 0));
        $target = $originalBoardsAt->copy()->addMinute();
        if ($target->lt($now)) {
            $target = $now->copy();
        }
        $until = $target->copy()->startOfDay()->addDays((int) config('refunds.rebook.search_days'));

        $candidates = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->where('trip_id', '!=', $original->trip_id)
            ->whereDate('trip_date', '>=', $target->toDateString())
            ->whereDate('trip_date', '<=', $until->toDateString())
            ->whereHas('fleetRoute', fn ($q) => $q->where('route_id', $routeId))
            ->get();

        $found = [];
        foreach ($candidates as $candidate) {
            if (ServiceType::normalize($candidate->service_type) !== $service
                || ($candidate->leg_direction === 'reverse' ? 'reverse' : 'outbound') !== $direction
                || VehicleClass::normalize($candidate->fleetRoute->fleet?->vehicle_class) !== $class) {
                continue;
            }
            // The booking rule for standing places: only on the day itself.
            if ($seatType === 'standing' && $candidate->trip_date->toDateString() > $now->toDateString()) {
                continue;
            }
            $evaluated = $this->evaluate($candidate, $originId, $destinationId, $seatType, $target, $timezone);
            if ($evaluated) {
                $found[] = $evaluated;
            }
        }

        usort($found, fn ($a, $b) => $a['boards_at']->getTimestamp() <=> $b['boards_at']->getTimestamp());

        return array_map(fn ($candidate) => $this->present($candidate, $seatType), array_slice($found, 0, max(1, $limit)));
    }
}
