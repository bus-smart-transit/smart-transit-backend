<?php

namespace App\Services;

use App\Repositories\TripAssignmentRequestRepository;
use App\Repositories\TripRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * C8: role-scoped quick summary for the dashboard calendar strip. Day and week
 * boundaries are business (Manila) dates, never UTC or browser dates, and the
 * week start comes from config/calendar.php.
 */
class CalendarSummaryService
{
    public function __construct(
        private TripRepository $tripRepository,
        private TripAssignmentRequestRepository $requestRepository,
    ) {}

    public function summaryFor(string $role, int $companyUserId): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $today = Carbon::today($timezone);
        $weekStart = $today->copy()->startOfWeek((int) config('calendar.week_start_day', 0));
        $weekEnd = $weekStart->copy()->addDays(6);

        $weekTrips = $this->tripRepository->listForCalendar($role, $companyUserId, $weekStart->toDateString(), $weekEnd->toDateString());
        $todayTrips = $weekTrips->filter(fn ($trip) => $this->dateOf($trip) === $today->toDateString());

        $summary = [
            'timezone' => $timezone,
            'week_start_day' => (int) config('calendar.week_start_day', 0),
            'today' => ['date' => $today->toDateString()] + $this->counts($todayTrips),
            'week' => ['start' => $weekStart->toDateString(), 'end' => $weekEnd->toDateString()] + $this->counts($weekTrips),
            'next' => $this->nextItem($role, $companyUserId, $today->toDateString()),
        ];

        if ($role === 'operator') {
            $summary['for_approval_count'] = $this->requestRepository->countPendingForOperator($companyUserId);
        }

        return $summary;
    }

    /** Trips are rows; a shift is a distinct shift block among those rows. */
    private function counts(Collection $trips): array
    {
        return [
            'trips' => $trips->count(),
            'shifts' => $trips->pluck('shift_block_id')->filter()->unique()->count(),
        ];
    }

    private function nextItem(string $role, int $companyUserId, string $fromDate): ?array
    {
        $trip = $this->tripRepository->findNextForCalendar($role, $companyUserId, $fromDate);
        if (! $trip) {
            return null;
        }

        $route = $trip->fleetRoute?->route;

        return [
            'type' => $trip->shift_block_id ? 'shift' : 'trip',
            'trip_id' => $trip->trip_id,
            'shift_block_id' => $trip->shift_block_id,
            'date' => $this->dateOf($trip),
            'departure_time' => $trip->departure_time,
            'status' => $trip->status,
            'route_name' => $route?->route_name,
            'origin' => $route?->origin,
            'destination' => $route?->destination,
        ];
    }

    private function dateOf(object $trip): string
    {
        return Carbon::parse($trip->trip_date)->toDateString();
    }
}
