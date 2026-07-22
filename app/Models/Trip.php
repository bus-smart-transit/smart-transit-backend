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

    protected $primaryKey = 'trip_id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'fleet_route_id',
        'company_user_id',
        'trip_date',
        'status',
        'current_seated_capacity',
        'current_standing_capacity',
        'total_occupancy',
        'driver_id',
        'conductor_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
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
     * Get the tickets for this trip.
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'trip_id', 'trip_id');
    }
}
