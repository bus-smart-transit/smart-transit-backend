<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperFleet
 */
class Fleet extends Model
{
    protected $table = 'fleets';
    protected $primaryKey = 'fleet_id';

    protected $fillable = [
        'company_user_id',
        'plate_number',
        'capacity',
        'seated_capacity',
        'standing_capacity',
        'status',
        'fleet_type', // 'public' | 'private'
    ];

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