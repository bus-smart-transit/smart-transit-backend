<?php

namespace App\Repositories;

use App\Models\CustomDropoffRestriction;

/** 2026-10-30: the restricted-area lookups DropoffRulesController made itself. */
class CustomDropoffRestrictionRepository
{
    public function findById(int $id): ?CustomDropoffRestriction
    {
        return CustomDropoffRestriction::find($id);
    }

    /** @return array<int, int> every route that has at least one restricted area */
    public function routeIds(): array
    {
        return CustomDropoffRestriction::query()->distinct()->pluck('route_id')->map(fn ($id) => (int) $id)->all();
    }
}
