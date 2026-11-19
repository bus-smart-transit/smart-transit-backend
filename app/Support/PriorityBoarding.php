<?php

namespace App\Support;

/**
 * Batch 76 (user, 2026-11-17): ONE owner for priority seats. A ticket may ask for a priority seat (senior citizen,
 * person with disability, pregnant). Nothing is checked online: the Chauffeur checks on the bus and moves a passenger
 * who does not qualify to a regular seat. Priority costs nothing and gives no discount, so there is little reason to
 * claim it falsely; at most config booking.priority.seats_per_bus priority tickets are sold online per trip.
 */
final class PriorityBoarding
{
    /** @return array<string, string> key => label */
    public static function types(): array
    {
        return (array) config('booking.priority.types', []);
    }

    public static function isType(?string $key): bool
    {
        return $key !== null && $key !== '' && array_key_exists($key, self::types());
    }

    public static function label(?string $key): ?string
    {
        return self::isType($key) ? self::types()[$key] : null;
    }

    public static function seatsPerBus(): int
    {
        return max(0, (int) config('booking.priority.seats_per_bus', 4));
    }

    /**
     * The priority a ticket asks for: the one chosen, or the one a senior or PWD discount implies (a discount type key
     * that is also a priority type). Null for none.
     */
    public static function resolve(?string $chosen, ?string $discountKey): ?string
    {
        if (self::isType($chosen)) {
            return $chosen;
        }

        return self::isType($discountKey) ? $discountKey : null;
    }

    /** @return list<array{key: string, label: string}> for the checkout form */
    public static function options(): array
    {
        return collect(self::types())->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all();
    }
}
