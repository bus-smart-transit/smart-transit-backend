<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fleet extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'fleets';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'company_user_id',
        'plate_number',
        'capacity',
        'seated_capacity',
        'standing_capacity',
        'status',
    ];

    /**
     * Get the companyUser that owns this record.
     */
    public function companyUser()
    {
        return $this->belongsTo(StaffUser::class, 'company_user_id');
    }
    public function fleetRoutes()
    {
        return $this->hasMany(FleetRoute::class, 'fleet_id', 'fleet_id');
    }

    public function fareRules()
    {
        return $this->hasMany(FareRule::class, 'fleet_id', 'fleet_id');
    }
}
