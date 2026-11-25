<?php

namespace App\Support;

use App\Models\Company;

/**
 * ONE owner for the baggage compartment prices.
 *
 * 2026-11-25 (user): baggage is priced by its size, the way terminals and bus companies quote it, and paid in cash to
 * the Chauffeur on board. Online the passenger only says they bring baggage; nothing is charged online. The Operator
 * sets each size's price for the company (companies.baggage_fee_*); a size with no price set uses the config default
 * (fares.baggage_sizes). This replaces Batch 76's fixed fee (half the fare, added to the ticket's amount); tickets sold
 * before keep that fee inside their amount.
 */
final class BaggageFee
{
    /** @var array<string, array{label: string, hint: string}> */
    public const SIZES = [
        'small' => ['label' => 'Small', 'hint' => 'A bag or a small box'],
        'medium' => ['label' => 'Medium', 'hint' => 'A sack, a big box or a large suitcase'],
        'large' => ['label' => 'Large', 'hint' => 'An appliance or a very big load'],
    ];

    public const MAX_PRICE = 5000;

    public static function isSize(?string $size): bool
    {
        return $size !== null && array_key_exists($size, self::SIZES);
    }

    /** @return array<int, string> */
    public static function sizeKeys(): array
    {
        return array_keys(self::SIZES);
    }

    public static function label(?string $size): ?string
    {
        return self::isSize($size) ? self::SIZES[$size]['label'] : null;
    }

    /** The default price of a size (config), whole pesos or centavos as set. */
    public static function defaultPrice(string $size): float
    {
        return round(max(0.0, (float) config("fares.baggage_sizes.{$size}", 0)), 2);
    }

    /** The price of a size for a company: the Operator's price, else the default. */
    public static function priceFor(?int $companyId, string $size): float
    {
        $company = $companyId ? Company::find($companyId) : null;
        $set = $company?->{'baggage_fee_'.$size};

        return $set !== null ? round((float) $set, 2) : self::defaultPrice($size);
    }

    /**
     * The price list of a company, in size order.
     *
     * @return array<int, array{key: string, label: string, hint: string, amount: float, is_default: bool}>
     */
    public static function listFor(?int $companyId): array
    {
        $company = $companyId ? Company::find($companyId) : null;

        return array_map(function (string $key) use ($company) {
            $set = $company?->{'baggage_fee_'.$key};

            return [
                'key' => $key,
                'label' => self::SIZES[$key]['label'],
                'hint' => self::SIZES[$key]['hint'],
                'amount' => $set !== null ? round((float) $set, 2) : self::defaultPrice($key),
                'is_default' => $set === null,
            ];
        }, self::sizeKeys());
    }
}
