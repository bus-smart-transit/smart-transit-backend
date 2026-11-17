<?php

namespace App\Support;

/**
 * Batch 76 (user, 2026-11-17): ONE owner for the baggage compartment fee. It is config fares.baggage_percent (50) of the
 * ticket's full fare, whole pesos (fare PHP 100, baggage PHP 50, ticket payment PHP 150). It is kept in its own column
 * (tickets.baggage_fee), never inside the fare, so a discount or a reward never changes it; a refunded ticket refunds it.
 */
final class BaggageFee
{
    public static function percent(): float
    {
        return max(0.0, (float) config('fares.baggage_percent', 50));
    }

    /** The fee for one ticket whose full fare (before any discount) is $fare. */
    public static function forFare(float $fare): float
    {
        return (float) round(max(0.0, $fare) * self::percent() / 100);
    }
}
