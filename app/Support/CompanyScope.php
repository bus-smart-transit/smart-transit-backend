<?php

namespace App\Support;

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batch 46 step 2 (decided 2026-11-06, least privilege): the one rule for "does this belong to the signed-in
 * Operator's company". A company may have several Operators.
 * - A bus (fleets) carries its company in fleets.company_id.
 * - Trips, shift blocks and other rows record the Operator who made them (company_user_id); they belong to that
 *   Operator's company, so any Operator of the company may see and act on them.
 * An Operator without a company is treated as a company of one (only their own rows), so nothing widens by mistake.
 * company_user_id stays on every table: it records who made the row, and the (unenforced) RLS policies read it.
 */
class CompanyScope
{
    /** The company a staff member (company_users row) works for, or null. */
    public static function companyIdOf(?int $companyUserId): ?int
    {
        if (! $companyUserId) {
            return null;
        }
        $companyId = StaffUser::whereKey($companyUserId)->value('company_id');

        return $companyId !== null ? (int) $companyId : null;
    }

    /**
     * The company_users ids of every Operator of the same company (always includes the given id).
     *
     * @return list<int>
     */
    public static function operatorIdsFor(?int $companyUserId): array
    {
        if (! $companyUserId) {
            return [];
        }
        $companyId = self::companyIdOf($companyUserId);
        if ($companyId === null) {
            return [$companyUserId];
        }

        $ids = StaffUser::where('company_id', $companyId)
            ->whereHas('user', fn ($q) => $q->where('role', 'operator'))
            ->pluck('company_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, $companyUserId]));
    }

    /** Do two company_users rows work for the same company (or are they the same person)? */
    public static function sameCompany(?int $a, ?int $b): bool
    {
        if (! $a || ! $b) {
            return false;
        }

        return in_array($b, self::operatorIdsFor($a), true);
    }

    /**
     * 2026-11-06: may this signed-in user act on a row owned by $rowCompanyId (routes, stops)? The platform Admin may;
     * staff only on their own company's rows (a row with no company is the Admin's).
     */
    public static function ownsRow(mixed $rowCompanyId, ?User $user): bool
    {
        if ($user?->role === 'admin') {
            return true;
        }
        $companyId = $user?->companyProfile?->company_id;

        return $companyId !== null && $rowCompanyId !== null && (int) $rowCompanyId === (int) $companyId;
    }

    /** The company of the signed-in staff member; null for the platform Admin (all companies); 0 when they have none. */
    public static function companyFilterFor(?User $user): ?int
    {
        if ($user?->role === 'admin') {
            return null;
        }

        return (int) ($user?->companyProfile?->company_id ?? 0);
    }

    /** Limits rows that record their Operator in $column to the Operator's company. */
    public static function whereMadeByCompany(Builder|QueryBuilder $query, string $column, ?int $operatorCompanyUserId): Builder|QueryBuilder
    {
        return $query->whereIn($column, self::operatorIdsFor($operatorCompanyUserId) ?: [0]);
    }

    /**
     * Limits a fleets query (or a join with fleets under $table) to buses of the Operator's company. A bus without a
     * company falls back to the Operator who registered it.
     */
    public static function whereFleetOfCompany(Builder|QueryBuilder $query, ?int $operatorCompanyUserId, string $table = 'fleets'): Builder|QueryBuilder
    {
        $companyId = self::companyIdOf($operatorCompanyUserId);

        return $query->where(function ($q) use ($companyId, $operatorCompanyUserId, $table) {
            if ($companyId !== null) {
                $q->where("{$table}.company_id", $companyId);
            }
            $q->orWhere(fn ($own) => $own->whereNull("{$table}.company_id")->where("{$table}.company_user_id", (int) $operatorCompanyUserId));
        });
    }
}
