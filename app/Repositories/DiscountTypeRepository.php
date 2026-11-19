<?php

namespace App\Repositories;

use App\Models\DiscountType;
use App\Models\OperatorDiscountSetting;
use Illuminate\Support\Collection;

/** 2026-10-27: discount types and each Operator's on/off choice. */
class DiscountTypeRepository
{
    /** @return Collection<int, DiscountType> */
    public function all(): Collection
    {
        return DiscountType::orderBy('sort_order')->orderBy('id')->get();
    }

    public function findByKey(string $key): ?DiscountType
    {
        return DiscountType::where('key', $key)->first();
    }

    /** @return array<int, bool> discount_type_id => active, only the types this Operator has set */
    public function settingsFor(int $companyUserId): array
    {
        return OperatorDiscountSetting::where('company_user_id', $companyUserId)
            ->pluck('active', 'discount_type_id')
            ->map(fn ($active) => (bool) $active)
            ->all();
    }

    public function setActive(int $companyUserId, int $discountTypeId, bool $active): void
    {
        OperatorDiscountSetting::updateOrCreate(
            ['company_user_id' => $companyUserId, 'discount_type_id' => $discountTypeId],
            ['active' => $active],
        );
    }
}
