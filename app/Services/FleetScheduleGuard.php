<?php

namespace App\Services;

use App\Models\FleetRoute;
use App\Models\Trip;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Two scheduling rules for a BUS (a fleet), checked when a trip or a shift block's legs are scheduled and when a trip is
 * moved to another bus. (Staff already cannot be double-booked: one assignment per person per day.)
 *
 * 1. Operating window: the departure must fall inside the window entered when the route was assigned to the bus
 *    (fleet_routes.start_time to end_time; a window that runs past midnight is read that way). Only the DEPARTURE is
 *    checked: a trip may end after the window closes.
 * 2. No double-booking: the bus cannot be on two trips at once. A trip occupies the bus from its departure for the
 *    route's expected run time (the stop offsets, with any peak-hour factor) plus the terminal turnaround buffer. When
 *    the route has no stop offsets the run time is unknown and only the turnaround buffer is used, so two trips
 *    that close together are still refused but a long run is not guessed at. Cancelled and completed trips do not
 *    occupy the bus.
 *
 * Nothing here moves or reschedules anything; it refuses, and says which trip is in the way.
 */
class FleetScheduleGuard
{
    /** Statuses in which a trip still has the bus. */
    public const OCCUPYING = ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'];

    public function __construct(private RouteDurationFactorService $durations) {}

    /**
     * @throws ValidationException
     */
    public function assertCanSchedule(int $fleetRouteId, string $tripDate, string $departureTime, ?string $legDirection = null, ?string $serviceType = null, ?int $ignoreTripId = null): void
    {
        $fleetRoute = FleetRoute::with('route')->find($fleetRouteId);
        if (! $fleetRoute) {
            return; // a missing fleet route is refused by the caller's own validation
        }

        $this->assertInsideOperatingWindow($fleetRoute, $departureTime);
        $this->assertBusIsFree($fleetRoute, $tripDate, $departureTime, $legDirection, $serviceType, $ignoreTripId);
    }

    private function assertInsideOperatingWindow(FleetRoute $fleetRoute, string $departureTime): void
    {
        if (! $fleetRoute->start_time || ! $fleetRoute->end_time) {
            return;
        }

        $start = $this->minutes((string) $fleetRoute->start_time);
        $end = $this->minutes((string) $fleetRoute->end_time);
        $at = $this->minutes($departureTime);

        $inside = $start <= $end
            ? ($at >= $start && $at <= $end)
            : ($at >= $start || $at <= $end); // the window runs past midnight

        if (! $inside) {
            throw ValidationException::withMessages([
                'departure_time' => [sprintf(
                    'This bus runs this route between %s and %s. A %s departure is outside those hours.',
                    $this->clock($start), $this->clock($end), $this->clock($at),
                )],
            ]);
        }
    }

    private function assertBusIsFree(FleetRoute $fleetRoute, string $tripDate, string $departureTime, ?string $legDirection, ?string $serviceType, ?int $ignoreTripId): void
    {
        $newStart = $this->minutes($departureTime);
        $newEnd = $newStart + $this->occupiedMinutes($fleetRoute, $tripDate, $departureTime, $legDirection, $serviceType);

        $others = Trip::with('fleetRoute.route')
            ->whereHas('fleetRoute', fn ($q) => $q->where('fleet_id', $fleetRoute->fleet_id))
            ->whereDate('trip_date', $tripDate)
            ->whereIn('status', self::OCCUPYING)
            ->when($ignoreTripId, fn ($q) => $q->where('trip_id', '!=', $ignoreTripId))
            ->get();

        foreach ($others as $other) {
            if (! $other->fleetRoute) {
                continue;
            }

            $otherStart = $this->minutes((string) $other->departure_time);
            $otherEnd = $otherStart + $this->occupiedMinutes($other->fleetRoute, $tripDate, (string) $other->departure_time, $other->leg_direction, $other->service_type);

            if ($newStart < $otherEnd && $otherStart < $newEnd) {
                throw ValidationException::withMessages([
                    'fleet_route_id' => [sprintf(
                        'This bus is already on %s from %s to about %s that day. Choose another bus or another time.',
                        $other->fleetRoute->route?->route_name ?? 'another trip', $this->clock($otherStart), $this->clock($otherEnd),
                    )],
                ]);
            }
        }
    }

    /** Minutes a trip keeps the bus: expected run (peak-hour factor included) plus the turnaround buffer. */
    private function occupiedMinutes(FleetRoute $fleetRoute, string $tripDate, string $departureTime, ?string $legDirection, ?string $serviceType): int
    {
        $buffer = (int) config('shift_blocks.terminal_turnaround_buffer_minutes', 30);
        $base = $this->durations->baseTotalMinutes((int) $fleetRoute->route_id, $legDirection, $serviceType);
        if ($base === null) {
            return $buffer;
        }

        $at = Carbon::parse($tripDate.' '.$departureTime, config('app.timezone', 'Asia/Manila'));
        $factor = $this->durations->factorFor((int) $fleetRoute->route_id, $legDirection, $at);

        return (int) round($base * $factor) + $buffer;
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    private function clock(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
