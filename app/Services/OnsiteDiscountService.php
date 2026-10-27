<?php

namespace App\Services;

use App\Models\DiscountType;
use App\Repositories\DiscountTypeRepository;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 2026-10-27: the one owner of onsite (cash) fare discounts.
 *
 *  - Discounts are for cash sales only. Online fares use reward points; an online item that names a discount is refused.
 *  - The Chauffeur checks the passenger's valid ID and ticks that it was checked. No ID number or photo is stored.
 *  - One discount per ticket. Each Operator turns each type on or off for their buses (no setting means on).
 *  - The discounted fare is the fare times (1 - rate), rounded once to the fare increment (whole pesos), nearest. The
 *    ticket keeps a snapshot: the type, its label and rate, the fare before the discount and the discount amount.
 */
class OnsiteDiscountService
{
    public function __construct(
        private DiscountTypeRepository $discountTypes,
        private FareCalculationService $fareCalculation,
    ) {}

    /** @return list<array{key: string, label: string, rate: float, active: bool}> every type, with this Operator's choice */
    public function listFor(?int $operatorCompanyUserId): array
    {
        $settings = $operatorCompanyUserId !== null ? $this->discountTypes->settingsFor($operatorCompanyUserId) : [];

        return $this->discountTypes->all()
            ->map(fn (DiscountType $type) => $this->present($type, $settings[$type->id] ?? true))
            ->values()
            ->all();
    }

    /** @return list<array{key: string, label: string, rate: float, active: bool}> the types a Chauffeur may apply on this Operator's buses */
    public function activeFor(?int $operatorCompanyUserId): array
    {
        return array_values(array_filter($this->listFor($operatorCompanyUserId), fn (array $type) => $type['active']));
    }

    /**
     * @return array{key: string, label: string, rate: float, active: bool}
     *
     * @throws ValidationException
     */
    public function setActive(int $operatorCompanyUserId, string $key, bool $active): array
    {
        $type = $this->discountTypes->findByKey($key);
        if (! $type) {
            throw ValidationException::withMessages(['discount_type_key' => ['This discount type does not exist.']]);
        }

        $this->discountTypes->setActive($operatorCompanyUserId, (int) $type->id, $active);
        AuditLogger::log('discount_type.'.($active ? 'activated' : 'deactivated'), 'DiscountType', (int) $type->id, ['key' => $type->key]);

        return $this->present($type, $active);
    }

    /**
     * The priced item with its discount applied (cash only). Without a discount the item comes back unchanged.
     *
     * @param  array<string, mixed>  $reserved  a priced item (priceItem's result)
     * @param  array<string, mixed>  $item  the requested item (discount_type_key, discount_id_checked)
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function apply(array $reserved, array $item, bool $onsite, int $operatorCompanyUserId): array
    {
        $key = $item['discount_type_key'] ?? null;
        if ($key === null || $key === '') {
            return $reserved;
        }

        if (! $onsite) {
            throw ValidationException::withMessages(['discount_type_key' => ['Discounts are given on the bus only. Online fares earn reward points instead.']]);
        }

        $type = collect($this->activeFor($operatorCompanyUserId))->firstWhere('key', (string) $key);
        if (! $type) {
            throw ValidationException::withMessages(['discount_type_key' => ['This discount is not offered on this bus.']]);
        }

        if (! filter_var($item['discount_id_checked'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['discount_id_checked' => ['Check the passenger\'s valid ID before applying this discount.']]);
        }

        $original = (float) $reserved['amount'];
        $discounted = $this->fareCalculation->roundAmount($original * (1 - $type['rate']));

        return array_merge($reserved, [
            'amount' => $discounted,
            'original_amount' => $original,
            'discount_amount' => round($original - $discounted, 2),
            'discount_type_key' => $type['key'],
            'discount_label' => $type['label'],
            'discount_rate' => $type['rate'],
            'discount_id_checked_at' => Carbon::now(),
        ]);
    }

    /** @return array{key: string, label: string, rate: float, active: bool} */
    private function present(DiscountType $type, bool $active): array
    {
        return [
            'key' => (string) $type->key,
            'label' => (string) $type->label,
            'rate' => (float) $type->rate,
            'active' => $active,
        ];
    }
}
