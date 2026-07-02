<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'trips';

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
        return $this->belongsTo(FleetRoute::class, 'fleet_route_id');
    }
}
