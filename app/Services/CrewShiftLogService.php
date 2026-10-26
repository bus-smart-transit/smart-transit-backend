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

    public static function today(): string
    {
        return Carbon::now(config('app.timezone', 'Asia/Manila'))->toDateString();
    }
}
