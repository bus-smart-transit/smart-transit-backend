<?php

namespace App\Services;

use App\Models\Trip;
use App\Repositories\TripRepository;
use App\Support\TripTiming;
use Illuminate\Support\Carbon;

/**
 * Batch 81 (user, 2026-11-19): figures for the Operator dashboard that need more than today's trip list. The one owner
 * of the On-time rule: a trip that left no later than dashboard.on_time_grace_minutes after its scheduled departure.
 */
class DashboardMetricsService
{
    public function __construct(private TripRepository $trips) {}

    /**
     * Today's on-time rate and the same weekday a week earlier, from the trips that really left. A day with no departed
     * trip has percent null (shown as "Not provided", never 0).
     *
     * @return array{grace_minutes: int, today: array<string, mixed>, last_week: array<string, mixed>}
     */
    public function onTime(int $operatorCompanyUserId, ?Carbon $now = null): array
    {
        $today = ($now ?? Carbon::now('Asia/Manila'))->copy()->setTimezone('Asia/Manila');

        return [
            'grace_minutes' => $this->grace(),
            'today' => $this->onTimeFor($operatorCompanyUserId, $today->toDateString()),
            'last_week' => $this->onTimeFor($operatorCompanyUserId, $today->copy()->subWeek()->toDateString()),
        ];
    }

    /** @return array{date: string, departed: int, on_time: int, percent: int|null} */
    private function onTimeFor(int $operatorCompanyUserId, string $date): array
    {
        $departed = $this->trips->findDepartedForCompanyOnDate($operatorCompanyUserId, $date);
        $onTime = $departed->filter(fn (Trip $trip) => $this->leftOnTime($trip))->count();

        return [
            'date' => $date,
            'departed' => $departed->count(),
            'on_time' => $onTime,
            'percent' => $departed->isEmpty() ? null : (int) round($onTime / $departed->count() * 100),
        ];
    }

    private function leftOnTime(Trip $trip): bool
    {
        $scheduled = TripTiming::departureAt($trip);
        if ($scheduled === null || $trip->departed_at === null) {
            return false;
        }

        return Carbon::parse($trip->departed_at)->lte($scheduled->copy()->addMinutes($this->grace()));
    }

    private function grace(): int
    {
        return max(0, (int) config('dashboard.on_time_grace_minutes', 5));
    }
}
