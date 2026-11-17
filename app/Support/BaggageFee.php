<?php

namespace App\Support;

/**
 * Batch 76 (user, 2026-11-17): ONE owner for the baggage compartment fee. It is config fares.baggage_percent (50) of the
 * ticket's full fare, whole pesos (fare PHP 100, baggage PHP 50, ticket payment PHP 150). The user's decision: the fee is
 * added to the ticket's amount (Finance sees one sale); the ticket keeps only `has_baggage`. A discount is worked out on
 * the fare without it (the claim records that fare), and a refunded ticket refunds it with the rest of its amount.
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
