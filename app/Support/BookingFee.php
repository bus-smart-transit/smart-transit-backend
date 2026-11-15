<?php

namespace App\Support;

/**
 * The one owner of the online transaction fee (Batch 45 Feature A, changed in Batch 75).
 *
 * - Batch 75 (user, 2026-11-15): it is a "transaction fee" that follows PayMongo's published rate for the e-wallet the
 *   passenger chose (config fares.transaction_fee: GCash 2.23%, Maya 1.79%, plus 12% VAT on the fee, no fixed part),
 *   charged on the fares of the payment, once per payment however many tickets it holds. The rates are a capstone
 *   mock-up of PayMongo's own charge (test mode), not a live reading of it.
 * - It is stored apart from the fares (payments.fee_amount); fares, reward points and the Operator's revenue never
 *   include it.
 * - Cash sales on the bus pay no fee.
 * - When the Operator cancels the trip, the fee is refunded in full with the first refunded ticket of that payment
 *   (ticket_resolutions.fee_amount), so it is given back once.
 * - Legal note (limitations register L-65): DTI DAO 21-03 does not allow extra charges for paying digitally in real
 *   use; this fee is for the test-mode capstone only.
 */
final class BookingFee
{
    /**
     * The effective rate for a channel, as a fraction of the fares (PayMongo's rate with VAT on it); 0 when the channel
     * has no rate.
     */
    public static function rate(string $channel): float
    {
        $percent = (float) (config('fares.transaction_fee.rates_percent')[strtolower($channel)] ?? 0);
        $vat = (float) config('fares.transaction_fee.vat_percent', 0);

        return max(0.0, $percent / 100 * (1 + $vat / 100));
    }

    /** The fee for one online payment of these fares with this channel, in pesos, rounded to the centavo. */
    public static function forChannel(string $channel, float $fares): float
    {
        return round(max(0.0, $fares) * self::rate($channel), 2);
    }

    /**
     * What the booking page needs to show the fee before paying: each channel's effective rate in percent.
     *
     * @return array<string, float>
     */
    public static function ratesPercent(): array
    {
        $rates = [];
        foreach (array_keys((array) config('fares.transaction_fee.rates_percent', [])) as $channel) {
            $rates[$channel] = round(self::rate($channel) * 100, 4);
        }

        return $rates;
    }
}
