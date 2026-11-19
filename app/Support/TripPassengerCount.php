<?php

namespace App\Support;

/**
 * Batch 76 (user, 2026-11-17): ONE owner for a trip leg's passenger count, the figures an inspector compares with the
 * people on the bus. Counted from paid tickets only (a ticket of a payment that never went through is not a sale):
 *  - tickets_sold: every paid ticket of the leg (a rebooked ticket counts once, through its replacement);
 *  - scanned_in: tickets scanned on or sold on the bus (cash sales board at once);
 *  - on_board: scanned in and not yet off; this is the number to count heads against;
 *  - got_off, not_scanned (paid, still to board), missed (the bus left their stop), expired.
 * A sold ticket that was never scanned can never be counted as a passenger, so tickets made up to meet a quota show as
 * not scanned, missed or expired, not as people on board.
 */
final class TripPassengerCount
{
    /**
     * @param  array<string, int>  $byStatus  paid ticket count per ticket status
     * @return array{tickets_sold: int, scanned_in: int, on_board: int, got_off: int, not_scanned: int, missed: int, expired: int}
     */
    public static function from(array $byStatus): array
    {
        $count = fn (string $status) => (int) ($byStatus[$status] ?? 0);
        $sold = 0;
        foreach ($byStatus as $status => $n) {
            if ($status !== 'rebooked') {
                $sold += (int) $n;
            }
        }

        return [
            'tickets_sold' => $sold,
            'scanned_in' => $count('boarded') + $count('alighted'),
            'on_board' => $count('boarded'),
            'got_off' => $count('alighted'),
            'not_scanned' => $count('valid'),
            'missed' => $count('missed'),
            'expired' => $count('expired'),
        ];
    }
}
