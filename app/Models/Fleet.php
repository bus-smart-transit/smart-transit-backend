<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperFleet
 * @property int $fleet_id
 * @property int $company_user_id
 * @property string $plate_number
 * @property int $capacity
 * @property int $seated_capacity
 * @property int $standing_capacity
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $fleet_type
 * @property-read \App\Models\StaffUser $companyUser
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FareRule> $fareRules
 * @property-read int|null $fare_rules_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FleetRoute> $fleetRoutes
 * @property-read int|null $fleet_routes_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCompanyUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereFleetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereFleetType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet wherePlateNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereSeatedCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereStandingCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereUpdatedAt($value)
 * @mixin \Eloquent
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