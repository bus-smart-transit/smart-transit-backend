<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetRoute extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'fleets_routes';

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
