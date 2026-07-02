<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'routes';

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
