<?php

namespace App\Services;

use App\Models\PendingBoarding;
use App\Models\Trip;
use App\Repositories\ConductorShiftLogRepository;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Passenger In and snap back (2026-10-26). A cash fare for someone boarding between stops used to be priced from where
 * the bus was when the Chauffeur made the sale, so collecting later charged less for the same ride. Now:
 *
 *  - Passenger In (checkIn): the bus position when the passenger boards is snapped back to the last stop the leg has
 *    passed (MidTripBoardingService::lastStopPassed) and kept as a check-in.
 *  - The sale counts the fare from that stop (forSale), however far the bus has gone since. Without a check-in, the
 *    position at the sale is snapped back the same way (the fallback, never lower than the stage fare).
 *  - One check-in pays for one ticket: the sale claims it under a row lock (claim) and marks it sold (markSold).
 *  - At End Trip a check-in still waiting becomes unpaid (closeOpenForTrip): the leakage check on the Shift Summary
 *    and the Operator's remittances.
 *
 * The server alone decides the boarding stop; a client never sends a position or a stop for a check-in.
 */
class PendingBoardingService
{
    public function __construct(
        private MidTripBoardingService $midTrip,
        private ConductorShiftLogRepository $shiftLogs,
    ) {}

    /** @return array<string, mixed> */
    public function checkIn(Trip $trip, int $conductorCompanyUserId): array
    {
        if (! in_array($trip->status, ['departed', 'in-progress'], true)) {
            throw ValidationException::withMessages(['trip' => ['Passenger In is for a bus on the road. Before departure, sell from the boarding stop.']]);
        }

        $resolved = $this->midTrip->resolve($trip);
        if ($resolved['state'] !== MidTripBoardingService::STATE_GOOD) {
            throw ValidationException::withMessages(['boarding' => [($resolved['message'] ?? 'The bus position is not reliable.').' Pick the boarding stop when you make the sale.']]);
        }

        $point = $resolved['boarding_point'];
        $stop = $this->midTrip->lastStopPassed($trip, (float) $point['distance_from_origin_km']);
        if (! $stop) {
            throw ValidationException::withMessages(['boarding' => ['No stop has been passed yet. Pick the boarding stop when you make the sale.']]);
        }

        $checkIn = PendingBoarding::create([
            'trip_id' => $trip->trip_id,
            'conductor_company_user_id' => $conductorCompanyUserId,
            'conductor_shift_log_id' => $this->shiftLogs->findOpenByConductor($conductorCompanyUserId)?->shift_log_id,
            'stop_id' => $stop->stop_id,
            'raw_distance_m' => (int) $point['distance_from_origin_m'],
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'checked_in_at' => Carbon::now(),
            'status' => PendingBoarding::PENDING,
        ]);
        AuditLogger::log('boarding.checked_in', 'Trip', (int) $trip->trip_id, ['pending_boarding_id' => $checkIn->id, 'stop_id' => $stop->stop_id, 'raw_distance_m' => $checkIn->raw_distance_m]);

        return $this->present($checkIn->load('stop'));
    }

    /** @return list<array<string, mixed>> the check-ins of this trip still waiting for their fare, oldest first */
    public function waitingFor(int $tripId): array
    {
        return PendingBoarding::with('stop')->where('trip_id', $tripId)->where('status', PendingBoarding::PENDING)
            ->orderBy('checked_in_at')->get()->map(fn (PendingBoarding $p) => $this->present($p))->all();
    }

    /**
     * The sale's boarding stop for a cash item that names a check-in or asks for the bus position (boarding_mode gps):
     * the item comes back with origin_stop_id set, so it is priced by the ordinary stop-based rules. Reads only; the
     * sale claims the check-in separately.
     *
     * @throws ValidationException
     */
    public function forSale(Trip $trip, array $item): array
    {
        if (isset($item['pending_boarding_id'])) {
            $checkIn = PendingBoarding::where('id', (int) $item['pending_boarding_id'])->where('trip_id', $trip->trip_id)->first();
            if (! $checkIn || $checkIn->status !== PendingBoarding::PENDING) {
                throw ValidationException::withMessages(['pending_boarding_id' => ['This check-in is no longer waiting for payment. Refresh the list.']]);
            }
            $stopId = (int) $checkIn->stop_id;
            $source = 'check_in';
        } else {
            $resolved = $this->midTrip->resolve($trip);
            if ($resolved['state'] !== MidTripBoardingService::STATE_GOOD) {
                throw ValidationException::withMessages(['boarding' => [($resolved['message'] ?? 'The bus position is not reliable.').' Choose the boarding stop instead.']]);
            }
            $stop = $this->midTrip->lastStopPassed($trip, (float) $resolved['boarding_point']['distance_from_origin_km']);
            if (! $stop) {
                throw ValidationException::withMessages(['boarding' => ['No stop has been passed yet. Choose the boarding stop instead.']]);
            }
            $stopId = (int) $stop->stop_id;
            $source = 'bus_position';
        }

        unset($item['boarding_mode']);

        return array_merge($item, ['origin_stop_id' => $stopId, 'boarding_source' => $source]);
    }

    /**
     * Locks the check-in for this sale. Inside the sale's transaction: a second sale of the same check-in waits, then
     * finds it sold and is refused.
     *
     * @throws ValidationException
     */
    public function claim(int $pendingBoardingId, int $tripId): PendingBoarding
    {
        $checkIn = PendingBoarding::whereKey($pendingBoardingId)->where('trip_id', $tripId)->lockForUpdate()->first();
        if (! $checkIn || $checkIn->status !== PendingBoarding::PENDING) {
            throw ValidationException::withMessages(['pending_boarding_id' => ['This check-in is no longer waiting for payment. Refresh the list.']]);
        }

        return $checkIn;
    }

    public function markSold(PendingBoarding $checkIn, int $ticketId): void
    {
        $checkIn->update(['status' => PendingBoarding::SOLD, 'ticket_id' => $ticketId, 'closed_at' => Carbon::now()]);
    }

    /** End Trip: what is still waiting becomes unpaid (the leakage list); a cancelled trip closes them as cancelled. */
    public function closeOpenForTrip(int $tripId, string $status = PendingBoarding::UNPAID): int
    {
        return PendingBoarding::where('trip_id', $tripId)->where('status', PendingBoarding::PENDING)
            ->update(['status' => $status, 'closed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /** @return list<array<string, mixed>> the unpaid check-ins of these trips (a Chauffeur's shift), oldest first */
    public function unpaidFor(array $tripIds): array
    {
        return PendingBoarding::with('stop')->whereIn('trip_id', $tripIds)->where('status', PendingBoarding::UNPAID)
            ->orderBy('checked_in_at')->get()->map(fn (PendingBoarding $p) => $this->present($p))->all();
    }

    /** @return array<int, int> conductor_shift_log_id => number of unpaid check-ins */
    public function unpaidCountsByShift(array $shiftLogIds): array
    {
        if ($shiftLogIds === []) {
            return [];
        }

        return PendingBoarding::whereIn('conductor_shift_log_id', $shiftLogIds)->where('status', PendingBoarding::UNPAID)
            ->groupBy('conductor_shift_log_id')->selectRaw('conductor_shift_log_id, count(*) as unpaid')
            ->pluck('unpaid', 'conductor_shift_log_id')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string, mixed> */
    private function present(PendingBoarding $checkIn): array
    {
        return [
            'id' => (int) $checkIn->id,
            'trip_id' => (int) $checkIn->trip_id,
            'stop_id' => (int) $checkIn->stop_id,
            'stop_name' => $checkIn->stop?->stop_name ?? 'Not provided',
            'checked_in_at' => $checkIn->checked_in_at?->format('H:i'),
            'status' => $checkIn->status,
        ];
    }
}
