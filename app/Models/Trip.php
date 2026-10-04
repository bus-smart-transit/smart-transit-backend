<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property-read \App\Models\FleetRoute $fleetRoute
 * @mixin IdeHelperTrip
 * @property int $trip_id
 * @property int $fleet_route_id
 * @property int $company_user_id
 * @property \Illuminate\Support\Carbon $trip_date
 * @property string $status
 * @property int $current_seated_capacity
 * @property int $current_standing_capacity
 * @property int $total_occupancy
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $driver_id
 * @property int|null $conductor_id
 * @property int|null $last_acknowledged_stop_id
 * @property string|null $last_acknowledged_at
 * @property-read \App\Models\StaffUser $companyUser
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Ticket> $tickets
 * @property-read int|null $tickets_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCompanyUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereConductorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCurrentSeatedCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCurrentStandingCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereDriverId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereFleetRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereLastAcknowledgedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereLastAcknowledgedStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereTotalOccupancy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereTripDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Trip extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'trips';

    protected $appends = ['driver_request_status', 'conductor_request_status'];

    protected $primaryKey = 'trip_id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'fleet_route_id',
        'company_user_id',
        'trip_date',
        'departure_time',
        'trip_type',
        'leg_direction',
        'service_type', // regular | express (Batch 28)
        'return_departure_time',
        'status',
        'dispatch_decision',
        'dispatch_route',
        'dispatch_reason',
        'dispatch_decided_at',
        'current_seated_capacity',
        'current_standing_capacity',
        'total_occupancy',
        'driver_id',
        'conductor_id',
        'driver_accepted_at',
        'conductor_accepted_at',
        'delay_notified_at',
        'shift_block_id',
        'duration_factor',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trip_date' => 'date:Y-m-d',
            'boarding_at' => 'datetime',
            'departed_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_acknowledged_at' => 'datetime',
        ];
    }

    /**
     * Get the companyUser that owns this record.
     */
    public function companyUser()
    {
        return $this->belongsTo(StaffUser::class, 'company_user_id');
    }

    /**
     * Get the fleetRoute that owns this record.
     */
    public function fleetRoute()
    {
        return $this->belongsTo(FleetRoute::class, 'fleet_route_id', 'fleet_route_id');
    }

    /**
     * Assigned driver profile for this trip.
     */
    public function driver()
    {
        return $this->belongsTo(StaffUser::class, 'driver_id', 'company_user_id');
    }

    /**
     * Assigned conductor profile for this trip.
     */
    public function conductor()
    {
        return $this->belongsTo(StaffUser::class, 'conductor_id', 'company_user_id');
    }

    /**
     * Batch 18: the shift block this trip ("leg") belongs to, if any.
     */
    public function shiftBlock()
    {
        return $this->belongsTo(ShiftBlock::class, 'shift_block_id', 'shift_block_id');
    }

    /**
     * Get the tickets for this trip.
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'trip_id', 'trip_id');
    }

    /**
     * Batch 24 (C6): assignment-level decline requests (D6), separate from the
     * trip lifecycle status.
     */
    public function assignmentRequests()
    {
        return $this->hasMany(TripAssignmentRequest::class, 'trip_id', 'trip_id');
    }

    /** 'for_approval' | 'rejected' | null, only when assignmentRequests is eager loaded. */
    public function getDriverRequestStatusAttribute(): ?string
    {
        return $this->requestStatusFor('driver', $this->driver_id);
    }

    public function getConductorRequestStatusAttribute(): ?string
    {
        return $this->requestStatusFor('conductor', $this->conductor_id);
    }

    private function requestStatusFor(string $role, ?int $assignedStaffId): ?string
    {
        if (! $assignedStaffId || ! $this->relationLoaded('assignmentRequests')) {
            return null;
        }

        // Only a request from the staff member currently holding the
        // assignment counts (a replacement starts with a clean slate).
        $latest = $this->assignmentRequests
            ->where('role', $role)
            ->where('staff_company_user_id', $assignedStaffId)
            ->sortByDesc('id')
            ->first();

        return in_array($latest?->status, [TripAssignmentRequest::FOR_APPROVAL, TripAssignmentRequest::REJECTED], true)
            ? $latest->status
            : null;
    }
}
