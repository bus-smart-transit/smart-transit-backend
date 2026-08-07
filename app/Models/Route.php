<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RouteStop> $routeStops
 * @mixin IdeHelperRoute
 * @property int $route_id
 * @property string $origin
 * @property string $destination
 * @property string $route_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FleetRoute> $fleetRoutes
 * @property-read int|null $fleet_routes_count
 * @property-read int|null $route_stops_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereDestination($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereOrigin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereRouteName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Route extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'routes';
    protected $primaryKey = 'route_id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'origin',
        'destination',
        'route_name',
    ];
    public function routeStops()
    {
        return $this->hasMany(RouteStop::class, 'route_id', 'route_id')->orderBy('stop_order');
    }

    public function fleetRoutes()
    {
        return $this->hasMany(FleetRoute::class, 'route_id', 'route_id');
    }
}