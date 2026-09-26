<?php

namespace App\Repositories;

use App\Models\ShiftBlock;
use Illuminate\Support\Collection;

class ShiftBlockRepository
{
    public function create(array $payload): ShiftBlock
    {
        return ShiftBlock::create($payload);
    }

    public function findById(int $id): ?ShiftBlock
    {
        return ShiftBlock::with(['fleet', 'driver.user', 'conductor.user', 'legs.fleetRoute.route', 'legs.fleetRoute.fleet'])
            ->find($id);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return ShiftBlock::where('shift_block_id', $id)->update(['status' => $status]) > 0;
    }

    public function updateAuditStatus(int $id, string $status): bool
    {
        return ShiftBlock::where('shift_block_id', $id)->update(['audit_status' => $status]) > 0;
    }

    public function update(int $id, array $payload): bool
    {
        return ShiftBlock::where('shift_block_id', $id)->update($payload) > 0;
    }

    /**
     * One-block-per-day exclusivity — a driver or conductor may only hold
     * one shift block per calendar date.
     */
    public function hasBlockOnDate(string $role, int $companyUserId, string $date, ?int $excludeBlockId = null): bool
    {
        $column = $role === 'driver' ? 'driver_id' : 'conductor_id';

        $query = ShiftBlock::where($column, $companyUserId)
            ->whereDate('scheduled_date', $date)
            ->where('status', '!=', 'cancelled');

        if ($excludeBlockId) {
            $query->where('shift_block_id', '!=', $excludeBlockId);
        }

        return $query->exists();
    }

    /**
     * Rest Rule support: the most recently COMPLETED block for this staff
     * member (any role column), used to check the minimum rest threshold
     * before their next block may start.
     */
    public function findMostRecentCompletedBlock(string $role, int $companyUserId): ?ShiftBlock
    {
        $column = $role === 'driver' ? 'driver_id' : 'conductor_id';

        return ShiftBlock::where($column, $companyUserId)
            ->where('status', 'completed')
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * Is this fleet already committed to another non-terminal (scheduled
     * or in-progress) shift block? Used to prevent two blocks running on
     * the same bus at once.
     */
    public function findActiveForFleet(int $fleetId, ?int $excludeBlockId = null): ?ShiftBlock
    {
        $query = ShiftBlock::where('fleet_id', $fleetId)
            ->where('status', 'in_progress');

        if ($excludeBlockId) {
            $query->where('shift_block_id', '!=', $excludeBlockId);
        }

        return $query->first();
    }

    public function findPendingReview(int $operatorCompanyUserId): Collection
    {
        return ShiftBlock::with(['fleet', 'driver.user', 'conductor.user'])
            ->where('company_user_id', $operatorCompanyUserId)
            ->where('audit_status', 'pending_review')
            ->orderByDesc('scheduled_date')
            ->get();
    }

    public function listForOperator(int $operatorCompanyUserId, ?string $statusFilter = null): Collection
    {
        $query = ShiftBlock::with(['fleet', 'driver.user', 'conductor.user'])
            ->where('company_user_id', $operatorCompanyUserId)
            ->orderByDesc('scheduled_date');

        if ($statusFilter && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        return $query->get();
    }

    public function listForDriver(int $driverCompanyUserId): Collection
    {
        return ShiftBlock::with(['fleet', 'driver.user', 'conductor.user', 'legs.fleetRoute.route'])
            ->where('driver_id', $driverCompanyUserId)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('scheduled_date')
            ->get();
    }

    public function listForConductor(int $conductorCompanyUserId): Collection
    {
        return ShiftBlock::with(['fleet', 'driver.user', 'conductor.user', 'legs.fleetRoute.route'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('scheduled_date')
            ->get();
    }
}
