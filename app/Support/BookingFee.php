<?php

namespace App\Support;

/**
 * Batch 45 Feature A (decided 2026-11-04): the one owner of the online booking fee.
 *
 * - A fixed fee per ONLINE payment (config fares.online_booking_fee, PHP 10), whatever the number of tickets. It is
 *   the developers' commission (profit), not a share of PayMongo's own charge, and it is stored apart from the fares
 *   (payments.fee_amount); fares, reward points and the Operator's revenue never include it.
 * - Cash sales on the bus pay no fee.
 * - When the Operator cancels the trip, the fee is refunded in full with the first refunded ticket of that payment
 *   (ticket_resolutions.fee_amount), so it is given back once.
 * - Legal note (limitations register L-65): DTI DAO 21-03 does not allow extra charges for paying digitally in real
 *   use; this fee is for the test-mode capstone only.
 */
final class BookingFee
{
    /** The fee for one online payment, in pesos (0 turns the fee off). */
    public static function online(): float
    {
        return max(0.0, round((float) config('fares.online_booking_fee', 0), 2));
    }

    /** The fee for a payment made with this method ('online' or 'cash'). */
    public static function forMethod(string $paymentMethod): float
    {
        return $paymentMethod === 'online' ? self::online() : 0.0;
    }
}
