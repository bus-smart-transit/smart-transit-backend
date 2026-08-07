<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property-read \App\Models\Fleet $fleet
 * @mixin IdeHelperFleetRoute
 * @property int $fleet_route_id
 * @property int $fleet_id
 * @property int $route_id
 * @property string $start_time
 * @property string $end_time
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Route $route
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Trip> $trips
 * @property-read int|null $trips_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereEndTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereFleetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereFleetRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereStartTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class FleetRoute extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'fleets_routes';
    protected $primaryKey = 'fleet_route_id';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'fleet_id',
        'route_id',
        'start_time',
        'end_time',
        'status',
    ];

    /**
     * Get the fleet that owns this record.
     */
    public function fleet()
    {
        return $this->belongsTo(Fleet::class, 'fleet_id');
    }

    /**
     * Get the route that owns this record.
     */
    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'fleet_route_id', 'fleet_route_id');
    }
}