<?php

namespace App\Repositories;

use App\Models\TripAssignmentRequest;
use Illuminate\Support\Collection;

class TripAssignmentRequestRepository
{
    public function create(array $payload): TripAssignmentRequest
    {
        return TripAssignmentRequest::create($payload);
    }

    public function findById(int $id): ?TripAssignmentRequest
    {
        return TripAssignmentRequest::with(['trip.fleetRoute.route', 'trip.companyUser.user', 'staff.user'])->find($id);
    }

    /** Latest request (any status) for this trip and role, if any. */
    public function latestFor(int $tripId, string $role): ?TripAssignmentRequest
    {
        return TripAssignmentRequest::where('trip_id', $tripId)
            ->where('role', $role)
            ->orderByDesc('id')
            ->first();
    }

    /** Pending requests for trips owned by this operator, oldest first. */
    public function listPendingForOperator(int $operatorCompanyUserId): Collection
    {
        return TripAssignmentRequest::with(['trip.fleetRoute.route', 'trip.fleetRoute.fleet', 'staff.user'])
            ->where('status', TripAssignmentRequest::FOR_APPROVAL)
            ->whereHas('trip', fn ($q) => $q->where('company_user_id', $operatorCompanyUserId))
            ->orderBy('created_at')
            ->get();
    }

    public function countPendingForOperator(int $operatorCompanyUserId): int
    {
        return TripAssignmentRequest::where('status', TripAssignmentRequest::FOR_APPROVAL)
            ->whereHas('trip', fn ($q) => $q->where('company_user_id', $operatorCompanyUserId))
            ->count();
    }

    /**
     * Atomic decision: only a request still awaiting approval can be decided,
     * so a double tap or two operators cannot decide it twice.
     */
    public function decide(int $id, string $status, int $deciderCompanyUserId): bool
    {
        return TripAssignmentRequest::where('id', $id)
            ->where('status', TripAssignmentRequest::FOR_APPROVAL)
            ->update([
                'status' => $status,
                'decided_by_company_user_id' => $deciderCompanyUserId,
                'decided_at' => now(),
            ]) > 0;
    }
}
