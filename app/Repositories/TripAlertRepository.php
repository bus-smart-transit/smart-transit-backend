<?php

namespace App\Repositories;

use App\Models\TripAlert;
use App\Support\CompanyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Batch 58: queries for crew alerts ("Alert operator"). The rules are in TripAlertService.
 */
class TripAlertRepository
{
    private const WITH = ['trip.fleetRoute.route', 'trip.fleetRoute.fleet', 'trip.driver', 'trip.conductor', 'raisedBy', 'handledBy'];

    public function create(array $payload): TripAlert
    {
        return TripAlert::create($payload);
    }

    public function find(int $alertId): ?TripAlert
    {
        return TripAlert::with(self::WITH)->find($alertId);
    }

    /** An alert of this type on this trip, still open, raised since $since (a second press returns it). */
    public function findOpenSince(int $tripId, string $type, Carbon $since): ?TripAlert
    {
        return TripAlert::with(self::WITH)
            ->where('trip_id', $tripId)
            ->where('type', $type)
            ->where('status', TripAlert::OPEN)
            ->where('created_at', '>=', $since)
            ->latest('alert_id')
            ->first();
    }

    /**
     * The company's alerts for the Operator: every open one, and the others raised since $since, newest first. Scoped
     * like the rest of the portal: trips made by any Operator of the caller's company (CompanyScope).
     *
     * @return Collection<int, TripAlert>
     */
    public function listForOperator(int $operatorCompanyUserId, Carbon $since): Collection
    {
        return TripAlert::with(self::WITH)
            ->whereHas('trip', fn ($trip) => CompanyScope::whereMadeByCompany($trip, 'company_user_id', $operatorCompanyUserId))
            ->where(fn ($q) => $q->where('status', TripAlert::OPEN)->orWhere('created_at', '>=', $since))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('alert_id')
            ->limit(100)
            ->get();
    }

    /** @return Collection<int, TripAlert> The alerts raised on one trip, newest first (the crew's screen). */
    public function listForTrip(int $tripId): Collection
    {
        return TripAlert::with(self::WITH)->where('trip_id', $tripId)->orderByDesc('alert_id')->get();
    }

    public function update(TripAlert $alert, array $changes): TripAlert
    {
        $alert->forceFill($changes)->save();

        return $alert->fresh(self::WITH);
    }
}
