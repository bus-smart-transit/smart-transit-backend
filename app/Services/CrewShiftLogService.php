<?php

namespace App\Services;

use App\Repositories\ConductorShiftLogRepository;
use App\Repositories\DriverShiftLogRepository;
use Illuminate\Support\Carbon;

/**
 * §4.8 (smarttransit_prompt.md): Start Shift and End Shift are the crew's time in and time out. They were stored
 * (driver_shift_logs, conductor_shift_logs) but no Operator screen showed them; this is the read-only view of one day,
 * for the Operator's own buses. It is not an attendance system: nothing here can be changed.
 */
class CrewShiftLogService
{
    public function __construct(
        private DriverShiftLogRepository $driverLogs,
        private ConductorShiftLogRepository $conductorLogs,
    ) {}

    /**
     * @return list<array{role: string, name: string, route: ?string, bus: ?string, time_in: string, time_out: ?string, minutes: ?int, open: bool}>
     */
    public function forOperatorOnDate(int $operatorCompanyUserId, string $date): array
    {
        $row = fn (string $role, ?object $person, object $log) => [
            'role' => $role,
            'name' => (string) ($person?->name ?: $person?->user?->username ?: 'Not provided'),
            'route' => $log->trip?->fleetRoute?->route?->route_name,
            'bus' => $log->trip?->fleetRoute?->fleet?->plate_number,
            'time_in' => $log->started_at->format('H:i'),
            'time_out' => $log->ended_at?->format('H:i'),
            'minutes' => $log->ended_at ? (int) $log->started_at->diffInMinutes($log->ended_at) : null,
            'open' => $log->ended_at === null,
            'started_at' => $log->started_at->format('Y-m-d H:i:s'),
        ];

        $rows = $this->driverLogs->forOperatorOnDate($operatorCompanyUserId, $date)->map(fn ($log) => $row('Driver', $log->driver, $log))
            ->concat($this->conductorLogs->forOperatorOnDate($operatorCompanyUserId, $date)->map(fn ($log) => $row('Chauffeur', $log->conductor, $log)))
            ->sortBy('started_at')
            ->values();

        return $rows->map(function (array $item) {
            unset($item['started_at']);

            return $item;
        })->all();
    }

    public const MAX_RANGE_DAYS = 31;

    public const PER_PAGE = 20;

    /**
     * Batch 46 item 4: the time in / time out table for a date range (at most 31 days, like the schedule tool). One row
     * per shift: who, role, bus, shift (Morning or Afternoon for a shift block, "Single trip" otherwise), the date it
     * started, time in, time out (with the day it ended when that is a later day), minutes worked. A running shift has
     * no minutes. Breaks are not recorded, so they are not shown or taken off. Also the minutes per person for the
     * whole range (finished shifts only).
     */
    public function forOperatorBetween(int $operatorCompanyUserId, string $from, string $to, ?int $staffCompanyUserId, int $page): array
    {
        $row = fn (string $role, ?object $person, object $log) => [
            'staff_id' => (int) ($role === 'Driver' ? $log->driver_company_user_id : $log->conductor_company_user_id),
            'role' => $role,
            'name' => (string) ($person?->name ?: $person?->user?->username ?: 'Not provided'),
            'route' => $log->trip?->fleetRoute?->route?->route_name,
            'bus' => $log->trip?->fleetRoute?->fleet?->plate_number,
            'shift' => match ($log->trip?->shiftBlock?->block_type) {
                'morning' => 'Morning',
                'afternoon' => 'Afternoon',
                default => 'Single trip',
            },
            'date' => $log->started_at->toDateString(),
            'time_in' => $log->started_at->format('H:i'),
            'time_out' => $log->ended_at?->format('H:i'),
            'ended_on' => $log->ended_at && $log->ended_at->toDateString() !== $log->started_at->toDateString() ? $log->ended_at->toDateString() : null,
            'minutes' => $log->ended_at ? (int) $log->started_at->diffInMinutes($log->ended_at) : null,
            'open' => $log->ended_at === null,
            'started_at' => $log->started_at->format('Y-m-d H:i:s'),
        ];

        $rows = $this->driverLogs->forOperatorBetween($operatorCompanyUserId, $from, $to, $staffCompanyUserId)->map(fn ($log) => $row('Driver', $log->driver, $log))
            ->concat($this->conductorLogs->forOperatorBetween($operatorCompanyUserId, $from, $to, $staffCompanyUserId)->map(fn ($log) => $row('Chauffeur', $log->conductor, $log)))
            ->sortBy('started_at')
            ->values();

        $totals = $rows->groupBy(fn ($item) => $item['role'].'-'.$item['staff_id'])->map(fn ($items) => [
            'staff_id' => $items->first()['staff_id'],
            'name' => $items->first()['name'],
            'role' => $items->first()['role'],
            'shifts' => $items->count(),
            'minutes' => (int) $items->sum(fn ($item) => $item['minutes'] ?? 0),
            'still_open' => $items->contains(fn ($item) => $item['open']),
        ])->sortBy('name')->values()->all();

        $lastPage = max(1, (int) ceil($rows->count() / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        return [
            'from' => $from,
            'to' => $to,
            'shifts' => $rows->forPage($page, self::PER_PAGE)->map(function (array $item) {
                unset($item['started_at']);

                return $item;
            })->values()->all(),
            'totals' => $totals,
            'pagination' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $rows->count(), 'last_page' => $lastPage],
        ];
    }

    public static function today(): string
    {
        return Carbon::now(config('app.timezone', 'Asia/Manila'))->toDateString();
    }
}
