<?php

namespace App\Support;

use App\Models\Trip;
use Illuminate\Support\Carbon;

/**
 * Phase A (smarttransit_prompt.md §4.11): the only place that knows WHEN a trip action may happen. Times are the
 * scheduled departure (trips.trip_date + departure_time) on the Manila wall clock, compared with the server clock.
 *
 *  - Pairing (pairing code and pairing) opens trip_timing.pairing_opens_minutes_before the departure. A shift block
 *    pairs once, so the caller passes the block's first leg (LegProgressionService::pairingScopeTripId).
 *  - Start Boarding opens trip_timing.boarding_window_minutes before the leg's departure.
 *  - Depart is allowed at or after the departure, and stays allowed when late.
 *
 * A trip without a usable date or time is never blocked here: the rule cannot be read, so the old behaviour stands.
 */
final class TripTiming
{
    public static function departureAt(Trip $trip): ?Carbon
    {
        $date = $trip->trip_date?->toDateString();
        $time = (string) ($trip->departure_time ?? '');
        if (! $date || ! preg_match('/^\d{1,2}:\d{2}/', $time)) {
            return null;
        }

        return Carbon::parse($date.' '.substr($time, 0, 5), self::timezone());
    }

    public static function pairingOpensAt(Trip $anchor): ?Carbon
    {
        return self::departureAt($anchor)?->subMinutes((int) config('trip_timing.pairing_opens_minutes_before', 60));
    }

    public static function boardingOpensAt(Trip $trip): ?Carbon
    {
        return self::departureAt($trip)?->subMinutes((int) config('trip_timing.boarding_window_minutes', 30));
    }

    public static function pairingBlockedReason(Trip $anchor, ?Carbon $now = null): ?string
    {
        $opensAt = self::pairingOpensAt($anchor);

        return $opensAt && self::now($now)->lt($opensAt) ? 'Pairing opens '.self::when($opensAt, $now).'.' : null;
    }

    public static function boardingBlockedReason(Trip $trip, ?Carbon $now = null): ?string
    {
        $opensAt = self::boardingOpensAt($trip);

        return $opensAt && self::now($now)->lt($opensAt) ? 'Boarding opens '.self::when($opensAt, $now).'.' : null;
    }

    public static function departBlockedReason(Trip $trip, ?Carbon $now = null): ?string
    {
        $departureAt = self::departureAt($trip);

        return $departureAt && self::now($now)->lt($departureAt) ? 'You can depart '.self::when($departureAt, $now).'.' : null;
    }

    /**
     * The opening times the Driver and Chauffeur screens show next to the buttons (ISO 8601 with offset). The screens
     * only mirror these; the server refuses an early action on its own.
     *
     * @return array{departure_at: ?string, boarding_opens_at: ?string, server_now: string}
     */
    public static function forTrip(Trip $trip): array
    {
        return [
            'departure_at' => self::departureAt($trip)?->toIso8601String(),
            'boarding_opens_at' => self::boardingOpensAt($trip)?->toIso8601String(),
            'server_now' => self::now(null)->toIso8601String(),
        ];
    }

    /** "at 6:30 AM" today, "on Oct 28 at 6:30 AM" on another day. No timezone label: every time shown is Manila. */
    private static function when(Carbon $at, ?Carbon $now): string
    {
        $at = $at->copy()->setTimezone(self::timezone());
        $clock = $at->format('g:i A');

        return $at->isSameDay(self::now($now)) ? "at {$clock}" : 'on '.$at->format('M j').' at '.$clock;
    }

    private static function now(?Carbon $now): Carbon
    {
        return ($now ?? Carbon::now())->copy()->setTimezone(self::timezone());
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Manila');
    }
}
