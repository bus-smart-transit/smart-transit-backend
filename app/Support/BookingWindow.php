<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Batch 29, issue 4: the ONE definition of how far ahead a passenger may book.
 *
 * The number comes from config('booking.max_advance_days') (env BOOKING_MAX_ADVANCE_DAYS, default 30); 0 or less means
 * "no limit". Today and the last bookable date are Manila business days. The booking API returns the same three values
 * (today, max_advance_days, last_bookable_date) to the passenger screens and to the Operator's schedule form, and every
 * server check (Book Later, a link, the roundtrip return date, checkout) reads them from here, so a change of the
 * config moves the client and the server together.
 */
final class BookingWindow
{
    public static function maxDays(): int
    {
        return (int) config('booking.max_advance_days');
    }

    /** Start of the Manila business day of $now (default: now). */
    public static function today(?Carbon $now = null): Carbon
    {
        $tz = config('app.timezone', 'Asia/Manila');

        return ($now ? $now->copy()->setTimezone($tz) : Carbon::now($tz))->startOfDay();
    }

    /** The last Manila date that can be booked (inclusive), or null when there is no limit. */
    public static function lastDate(?Carbon $now = null): ?Carbon
    {
        $days = self::maxDays();

        return $days > 0 ? self::today($now)->addDays($days) : null;
    }

    public static function lastDateString(?Carbon $now = null): ?string
    {
        return self::lastDate($now)?->toDateString();
    }

    /** Is this Manila date inside the window (today up to the last bookable date, both inclusive)? */
    public static function isWithin(string $date, ?Carbon $now = null): bool
    {
        $day = substr($date, 0, 10);
        $last = self::lastDateString($now);

        return $day >= self::today($now)->toDateString() && ($last === null || $day <= $last);
    }

    /** What the date pickers and the Operator form need. */
    public static function describe(?Carbon $now = null): array
    {
        return [
            'today' => self::today($now)->toDateString(),
            'max_advance_days' => self::maxDays(),
            'last_bookable_date' => self::lastDateString($now),
        ];
    }

    /** The one sentence a 422 uses when a date is too far ahead. */
    public static function aheadMessage(?Carbon $now = null): string
    {
        $last = self::lastDate($now);

        return 'You can book up to '.self::maxDays().' days ahead'.($last ? ' (until '.$last->format('M j, Y').')' : '').'.';
    }
}
