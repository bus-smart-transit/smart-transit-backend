<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Batch 29, Stage 2: the ONE place the mixed timestamp conventions of the money tables are handled.
 *
 *  - payments.*, tickets.* and most other columns hold the MANILA wall-clock time (app.timezone is Asia/Manila).
 *  - onsite_payment.issued_at (cash tickets) and ticket_resolutions.completed_at (refunds) hold UTC.
 *
 * Everything the Financials page groups or filters by (Manila day, Manila hour) goes through here, so a cash ticket
 * sold at 06:04 on the 5th (stored 22:04 on the 4th in UTC) lands on the 5th, and a refund is compared as an instant.
 */
final class FinancialClock
{
    public static function tz(): string
    {
        return config('app.timezone', 'Asia/Manila');
    }

    public static function today(?Carbon $now = null): string
    {
        return ($now ?? Carbon::now(self::tz()))->copy()->setTimezone(self::tz())->toDateString();
    }

    /** A stored Manila wall-clock value, as a Manila instant. */
    public static function fromWallClock(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value, self::tz()) : null;
    }

    /** A stored UTC value, as a Manila instant. */
    public static function fromUtc(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value, 'UTC')->setTimezone(self::tz()) : null;
    }

    /**
     * When a payment became revenue (Manila). A cash ticket: the time it was issued (UTC on onsite_payment). An online
     * payment: the moment its tickets were created, which is when the signed webhook confirmed it; the checkout time
     * (payment_created) is only a fallback. A rebooked ticket's replacement is created later, so the earliest ticket wins.
     */
    public static function paidAt(string $method, ?string $onsiteIssuedUtc, ?string $firstTicketAt, ?string $paymentCreated): ?Carbon
    {
        if ($method === 'cash' && $onsiteIssuedUtc) {
            return self::fromUtc($onsiteIssuedUtc);
        }

        return self::fromWallClock($firstTicketAt ?: $paymentCreated);
    }

    public static function startOfDay(string $date): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $date, self::tz())->startOfDay();
    }

    public static function endOfDay(string $date): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $date, self::tz())->endOfDay();
    }

    /** The same instant as a stored Manila wall-clock string (for SQL pre-filters). */
    public static function wallString(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(self::tz())->format('Y-m-d H:i:s');
    }

    /** The same instant as a stored UTC string (for SQL pre-filters). */
    public static function utcString(Carbon $moment): string
    {
        return $moment->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
